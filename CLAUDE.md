# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a Drupal 11 site focused on the New England Patriots Dynasty, featuring content about games, players, podcast episodes, highlights, and timeline events. The site uses a custom Tailwind CSS + DaisyUI theme and includes custom modules for Dynasty-specific functionality.

## Development Environment

### DDEV Setup
The project uses DDEV for local development:

```bash
# Start the environment
ddev start

# Stop the environment
ddev stop

# SSH into the web container
ddev ssh

# Access database
ddev mysql
```

Project URL: https://dynasty9.ddev.site (or http://dynasty9.ddev.site)

### Environment Details
- **Drupal Version**: 11.x
- **PHP Version**: 8.3
- **Database**: MySQL 8.0
- **Web Server**: nginx-fpm
- **Document Root**: `web/`

### Production
A separate, resource-constrained production VPS exists (small: ~1.9GB RAM, PHP-FPM `memory_limit` tuned to 900M / `max_execution_time` to 60s specifically to give the two large flat-JSON search endpoints, above, room to complete their first computation after a cache invalidation -- that ini change lives only on the server, not in this repo). Connection details aren't recorded here; ask the user.

Two things that have caused real incidents there:
- **`drush updb`/`drush cim` are not optional after a deploy.** Production once went 5 commits/several weeks without either running, so the code (which no longer had the retired `play` entity's PHP class) and the database (which still had `field_scoring_plays` referencing that now-nonexistent entity type) diverged enough to throw a live `FieldException` on every `game`/`highlight`/`player` page. If an update hook needs a since-deleted class to resolve an entity type (e.g. `getStorage('some_deleted_type')`), it may need the class file temporarily restored (from the commit that deleted it) to run at all, then removed again afterward -- don't delete a custom entity type's PHP classes and drop its update hook in the same deploy without running `updb` in between, or a later catch-up deploy can hit exactly this.
- **`drush cr` has real cost on production.** It's small enough that clearing the cache forces the next visitor to recompute `/dynasty/search/stats` or `/dynasty/search/play-by-play` from scratch (multiple seconds to over a minute pre-optimization), and concurrent cold-cache requests from real traffic can pile up. Avoid unnecessary cache clears there; if one is needed, consider warming those two endpoints with a direct request immediately after.

## Common Development Commands

### Drush Commands
```bash
# Clear cache
ddev drush cr

# Import configuration
ddev drush cim -y

# Export configuration
ddev drush cex -y

# Run database updates
ddev drush updb -y

# Check migration status
ddev drush ms

# Run a specific migration
ddev drush mim [migration_id]

# Rollback a migration
ddev drush mr [migration_id]

# Entity updates
ddev drush entup -y
```

### Composer Commands
```bash
# Install dependencies
ddev composer install

# Require a new module
ddev composer require drupal/module_name

# Update dependencies
ddev composer update
```

### Theme Development (Tailwind CSS)
The custom theme is located at `web/themes/custom/dynasty_tw/`:

```bash
# Navigate to theme directory
cd web/themes/custom/dynasty_tw

# Install npm dependencies (if needed)
ddev exec npm install

# Build Tailwind CSS (if you have a build process configured)
# Note: Check package.json for available scripts
```

The theme uses:
- **Tailwind CSS 3.x** for styling
- **DaisyUI 4.x** for component library
- Custom theme colors based on Patriots branding (primary: #002244, secondary: #c60c30)

## Architecture

### Custom Modules
All custom modules are in `web/modules/custom/`:

- **dynasty_module**: Core Dynasty functionality, helpers, custom forms, and shared utilities
  - Contains DynastyHelpers class with utilities like passer rating calculations
  - Multiple administrative forms for data imports
  - Custom permissions and routing

- **dynasty_podcast**: Podcast-specific functionality
  - Manages podcast episode nodes
  - Handles Pro Football Reference (PFR) link generation
  - Wikipedia link generation for teams
  - Contains player statistics data in `player_stats/` and PFR data in `pfr_data/`

- **dynasty_transcript**: Custom entity for managing podcast transcripts

- **dynasty_timeline**: Timeline-related functionality

- **dynasty_images**: Image handling and processing

- **afc_east**: AFC East division-specific content

- **dynasty_plays**: Custom content entities for play-level data (see Custom Content Entities below), their CSV importers, and the shared `GamePlayerMatcher` service (`src/Service/GamePlayerMatcher.php`) that resolves a CSV/text row to a Game or Player node by date/name. Drush import commands live in `src/Commands/` (e.g. `dynasty:import-play-by-play`, `dynasty_plays:match-highlights`).

- **dynasty_search**: JSON data endpoints + page shells for the site's search pages (Game Search, Highlight Search, and the merged Player Stats/All Plays search). Each dataset is served once as flat JSON (`/dynasty/search/*`) and filtered/sorted/paginated entirely client-side in vanilla JS (`js/*.js`) — used instead of Search API/Solr for these small-to-medium, bounded datasets. See Search Architecture below.

### Custom Themes
Located in `web/themes/custom/`:

- **dynasty_tw**: Main front-end theme (Tailwind CSS + DaisyUI based)
  - Template suggestions for pages by node type
  - Custom views field templates
  - Theme preprocessing functions
  - Regions: header, primary_menu, secondary_menu, highlighted, content, sidebars, footer, etc.

- **dynasty_admin**: Custom admin theme (Gin-based)

### Content Types
The site uses these content types (defined in `config/sync/node.type.*.yml`):

- **game**: Football games with extensive stats, Brady stats, opponent info, scores
- **podcast_episode**: Podcast episodes with download stats, transcripts
- **highlight**: Game highlights and video clips
- **player**: Player information
- **team**: Team information with PFR IDs
- **article**: Standard articles
- **event**: Events
- **page**: Basic pages
- **podcast_data**: Podcast metadata
- **twitter_hidden_image**: Social media images

### Custom Content Entities
Beyond node-based content types, `dynasty_plays` defines its own content entity types (not nodes) for play-level data, populated by CSV import rather than migrations:

- **pbp_play**: One row per play (1978-present, ~134k rows), imported from the `patriots_pbp_<season>.csv` files bundled in `data/pbp/` — pre-2000 rows are scraped Pro Football Reference box-score prose, 2000+ rows are pro-football-reference-formatted text but actually sourced from nflfastR (see the `nfldata.org` sibling project's `scripts/extract-patriots-pbp.py`/`scripts/enrich-legacy-pbp.py`, which produce these CSVs; not part of this repo). Base fields include `pbp_game` (ref to `game`), `pbp_quarter`/`pbp_down`/`pbp_distance`/`pbp_location`, `pbp_detail` (free text), `pbp_player` (single ref to `player`, resolved from free text at import time — often NULL, since resolution is deliberately conservative: only an unambiguous single-player match is recorded), `pbp_scoring_play`/`pbp_scoring_team` (computed from the score delta between consecutive rows), and `pbp_highlight` (single ref to `highlight`, populated by `drush dynasty_plays:match-highlights`, which matches a highlight's game/quarter/down/distance and tie-breaks ambiguous matches against every player-role field on each candidate row — see `src/Commands/HighlightMatchCommands.php`; re-runnable, never overwrites an existing link). `pbp_play_type` (canonical pass/run/punt/kickoff/field_goal/extra_point/qb_kneel/qb_spike/no_play/penalty/other, ~99% populated) plus `pbp_two_point_attempt` and 14 single-value player-role fields (`pbp_passer`, `pbp_rusher`, `pbp_receiver`, `pbp_interceptor`, `pbp_sacker`, `pbp_punter`, `pbp_kicker`, `pbp_returner`, `pbp_blocker`, `pbp_tackler_1`/`pbp_tackler_2`, `pbp_forced_fumble_player`, `pbp_fumble_recovery_player`, `pbp_penalized_player`) come straight from the source CSV (nflfastR's own columns for 2000+, regex-derived for pre-2000) rather than being parsed at Drupal import time. All of these plus `pbp_player`/`pbp_scoring_play`/`pbp_scoring_team`/`pbp_highlight` are deliberately single-value/non-translatable so they stay plain columns on the `pbp_play` base table — see Search Architecture below for why that matters.
- **player_game_stat**: One row per player/game/quarter/stat-category (~25k rows), imported from the quarterly-stats CSV — covers 1978-2023. The 1978-1999 rows use PFR's raw box-score date/name formats rather than the CSV's original 2000+ "Y-m-d" / "Initial.Surname" shorthand; `GamePlayerMatcher::normalizeGameDate()` and `::matchPlayerFullName()` (called from `matchPlayer()` when a name has no ".") handle both eras. This is also the only source of aggregated per-player Passing/Rushing/Receiving totals — `pbp_play`'s free-text detail lines aren't a substitute.

A `play` entity type (2006-only, hand-curated Scoring Play data) existed earlier in the project but was retired once `pbp_play`'s `pbp_scoring_play` gave the same category full historical coverage instead of one season; do not recreate it.

**Re-importing after a CSV edit**: neither `dynasty:import-play-by-play` nor `dynasty:import-quarterly-stats` dedupes against existing rows — always pass `--wipe` when re-running after a source CSV changes, or you'll get duplicates alongside the old data rather than a clean replace.

### Migrations
The project includes 9 migrations for importing historical data:

- `dynasty_taxonomy_import`: Import taxonomy terms
- `dynasty_player_import`: Import player data
- `dynasty_team_import`: Import team data
- `dynasty_team_paragraph_import`: Import team paragraphs
- `dynasty_game_import`: Import game data
- `dynasty_old_home_games_csv_import`: Import old home games from CSV
- `dynasty_old_away_games_csv_import`: Import old away games from CSV
- `dynasty_highlight_import`: Import highlight data

Migration configs are in `config/sync/migrate_plus.migration.*.yml`

Custom migration process plugins are in `web/modules/custom/dynasty_module/src/Plugin/migrate/process/`

### Configuration Management
Configuration is stored in `config/sync/` directory and synced via Drush commands (cim/cex).

The site has:
- 59+ Views configurations
- Faceted search using Search API + Solr, now scoped to podcast/transcript search only (`search_api.index.podcast_index`, `search_api.index.podcast_transcript_index`) -- Game/Highlight/Stat/Play search were migrated off Search API to the client-side flat-JSON pattern (see Search Architecture below); don't add a new Search API index for a similarly small/bounded dataset without considering that pattern first
- Entity browsers for content selection
- Paragraphs with layout paragraphs for flexible content
- Gutenberg editor integration

### Search Architecture
The site's own game/play/highlight/stat search pages (`dynasty_search` module) deliberately avoid Search API/Solr: each dataset is small and bounded, so the whole published dataset is served once as flat JSON (`/dynasty/search/games`, `/highlights`, `/stats`, `/play-by-play`) with a permanent cache, and all filtering/sorting/pagination happens client-side (select2 widgets + noUiSlider range sliders). Current pages:
- `/search/games`, `/search/highlights` — standalone pages, one dataset each.
- `/search/plays` — a single page with two client-side modes, "Player Stats" (per-player Passing/Rushing/Receiving lines from `player_game_stat`) and "All Plays" (the raw `pbp_play` log, with Play Type and Player filters, a "Scoring Play" toggle, and a "Scoring Team" filter — Play Type/Player come from `pbp_play_type` and the role fields via `SearchDataController::ROLE_FIELD_LABELS`, not free-text parsing). Only the active mode's markup is live in the DOM at load; the other mode's identical markup sits inert inside a `<template>` tag until first switched to (`js/plays-search-toggle.js`), so only one of the two (large) datasets is ever fetched per visit unless a visitor asks for both. `/search/stats` and `/search/play-by-play` are legacy URLs that 301-redirect here.
- Both `SearchDataController::playByPlay()` (`pbp_play`, ~134k rows) and `::stats()` (`player_game_stat`, ~25k rows) query their base table directly via raw SQL rather than the Entity API, resolving only the much smaller set of *distinct* Game/Player nodes through the Entity API (memoized per game via `::gameContext()`). This isn't just a speed optimization: loading every row as a full entity is expensive enough per-row that it exhausted PHP's memory limit outright on production once `player_game_stat` grew past ~25k rows (confirmed: raising `memory_limit` didn't help -- the box didn't have the real memory to spare, only the raw-SQL rewrite did). Preserve this pattern in both methods; don't reintroduce per-row `EntityStorage::load()`/`loadMultiple()` at this scale.
- `playByPlay()`'s JSON *response shape* is itself memory-sensitive at this row count, independent of the raw-SQL point above: repeating each row's game context (title/url/season/week/opponent, ~9 fields) and player names across ~135k rows was already the dominant cost (confirmed: the original per-row-denormalized version, with none of the play_type/role fields added when this line was written, peaked around 680MB building a ~90MB payload, and reliably exceeded PHP-FPM's memory_limit once Drupal's own page/dynamic-page cache write -- Redis here -- was included, which adds substantial overhead of its own on top of building the response). The response is normalized instead: `{games, players, play_type_labels, rows}`, with `games`/`players` small dictionaries (id → context/name) sent once and each row referencing them by ID rather than repeating their content -- `js/pbp-search.js`'s `denormalize()` expands this back into the flat per-row shape the rest of that file expects, once, right after fetch. Measures at ~540MB peak building a ~62MB response. Preserve this normalization (don't merge game/player context back onto each row server-side) and re-measure both the raw response and a real HTTP request (not just the controller in isolation -- the Redis cache write is where it actually fatals) before adding more per-row fields here.

### Key Contributed Modules
Notable modules (see `composer.json` for full list):

- **UI/Theme**: gin, gin_login, gutenberg, ui_suite_daisyui, tailwindcss
- **Content**: paragraphs, layout_paragraphs, entity_browser, inline_entity_form
- **Search**: search_api, search_api_solr, facets
- **Migration**: migrate_plus, migrate_tools, migrate_source_csv
- **SEO**: metatag, pathauto, simple_sitemap, redirect
- **Analytics**: google_tag, plausible
- **Media**: charts (with Highcharts 6.1.0 library)
- **Performance**: quicklink, cloudflare_purge
- **Admin**: admin_toolbar, field_group, field_permissions

### External Libraries
Highcharts 6.1.0 is installed via custom repository definitions in composer.json for data visualization and charts.

### Patches
The project applies patches to:
- `drupal/facets`: Select2 compatibility fix (#3446040)
- `drupal/views_ajax_history`: jQuery $.unique deprecation fix (#3499860)

## Development Workflow

### Making Code Changes
1. Custom module code goes in `web/modules/custom/[module_name]/`
2. Custom theme code goes in `web/themes/custom/dynasty_tw/`
3. Template files use `.html.twig` extension
4. Hook implementations go in `.module` or `.theme` files
5. Clear cache after code changes: `ddev drush cr`

### Configuration Changes
1. Make changes in the UI
2. Export: `ddev drush cex -y`
3. Review changes in `config/sync/`
4. Commit configuration files
5. On other environments: `ddev drush cim -y`

### Working with Migrations
1. Check status: `ddev drush ms`
2. Run migration: `ddev drush mim migration_id`
3. Rollback if needed: `ddev drush mr migration_id`
4. Reset and re-run: `ddev drush mr migration_id && ddev drush mim migration_id`

## File Locations

- **Custom modules**: `web/modules/custom/`
- **Custom themes**: `web/themes/custom/`
- **Configuration**: `config/sync/`
- **Contributed modules**: `web/modules/contrib/`
- **Contributed themes**: `web/themes/contrib/`
- **Libraries**: `web/libraries/`
- **Drush commands**: `drush/Commands/contrib/`
- **Private files**: Not in repository
- **Public files**: `web/sites/default/files/` (not in repository)

## Important Notes

- Configuration is managed in code via `config/sync/` - always export changes
- The site uses Composer for dependency management - never commit `vendor/` or `web/core/`
- Theme uses Tailwind with custom Patriots color scheme
- Many views are configured - check existing views before creating new ones
- Migration source data is stored in custom modules (player_stats, pfr_data)
- Play-level data (`pbp_play`, `player_game_stat`) is CSV-imported via `dynasty_plays` drush commands, not migrations -- the bundled CSVs are in `web/modules/custom/dynasty_plays/data/`. Editing/replacing a CSV does not itself change anything on the site; re-run the corresponding import command afterward (see Custom Content Entities above for the `--wipe` requirement).
- The dynasty_module contains a DynastyHelpers class with shared utility functions
- Podcast episodes automatically generate PFR and Wikipedia links on save
- The site uses Gutenberg editor for content editing
