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

- **pbp_play**: One row per play, imported from Pro Football Reference box-score CSVs (1978-present). Base fields include `pbp_game` (ref to `game`), `pbp_quarter`/`pbp_down`/`pbp_distance`/`pbp_location`, `pbp_detail` (free text), `pbp_player` (single ref to `player`, resolved from free text at import time — often NULL, since resolution is deliberately conservative: only an unambiguous single-player match is recorded), `pbp_scoring_play`/`pbp_scoring_team` (computed from the score delta between consecutive rows), and `pbp_highlight` (single ref to `highlight`, populated either by the 2 rows manually migrated from the retired `play` entity, or in bulk by `drush dynasty_plays:match-highlights`, which matches a highlight's game/quarter/down/distance and tie-breaks ambiguous matches using the highlight's tagged players — see `src/Commands/HighlightMatchCommands.php`). All of `pbp_player`/`pbp_scoring_play`/`pbp_scoring_team`/`pbp_highlight` are deliberately single-value/non-translatable so they stay plain columns on the `pbp_play` base table — `SearchDataController::playByPlay()` queries that table directly with raw SQL (bypassing the Entity API) since entity-loading its ~134k rows is far too slow for a request.
- **player_game_stat**: One row per player/game/quarter/stat-category, imported from a separate quarterly-stats CSV (2000+ seasons only — this is the only source of aggregated per-player Passing/Rushing/Receiving totals; there is no equivalent source for 1978-1999, so those seasons never appear in this entity).

A `play` entity type (2006-only, hand-curated Scoring Play data) existed earlier in the project but was retired once `pbp_play`'s `pbp_scoring_play` gave the same category full historical coverage instead of one season; do not recreate it.

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
- `/search/plays` — a single page with two client-side modes, "Player Stats" (per-player Passing/Rushing/Receiving lines from `player_game_stat`, plus "Scoring Play" rows from `pbp_play`) and "All Plays" (the raw `pbp_play` log). Only the active mode's markup is live in the DOM at load; the other mode's identical markup sits inert inside a `<template>` tag until first switched to (`js/plays-search-toggle.js`), so only one of the two (large) datasets is ever fetched per visit unless a visitor asks for both. `/search/stats` and `/search/play-by-play` are legacy URLs that 301-redirect here.
- `SearchDataController::playByPlay()` queries the `pbp_play` base table directly via raw SQL rather than the Entity API -- loading its ~134k rows through Entity API takes over a minute; the raw query is well under a second. Preserve this if touching that endpoint.

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
- Play-level data (`pbp_play`, `player_game_stat`) is CSV-imported via `dynasty_plays` drush commands, not migrations -- the bundled CSVs are in `web/modules/custom/dynasty_plays/data/`. Editing/replacing a CSV does not itself change anything on the site; re-run the corresponding import command (`dynasty:import-play-by-play`, `dynasty:import-quarterly-stats`) afterward. Neither command dedupes against existing rows, so re-running after an edit needs `--wipe` (clears that entity type first) to avoid duplicates rather than doubling up the previously-imported rows.
- The dynasty_module contains a DynastyHelpers class with shared utility functions
- Podcast episodes automatically generate PFR and Wikipedia links on save
- The site uses Gutenberg editor for content editing
