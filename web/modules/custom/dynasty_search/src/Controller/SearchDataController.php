<?php

namespace Drupal\dynasty_search\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\dynasty_module\DynastyHelpers;
use Drupal\node\Entity\Node;

/**
 * JSON data endpoints backing the Game Search and Highlight Search pages.
 *
 * These replace the old Solr/Search API-backed game_search and
 * highlight_search views: the data sets are small and bounded, so instead
 * of indexing them the whole (published) data set is served as flat JSON
 * once and filtered/sorted/paginated client-side.
 */
class SearchDataController extends ControllerBase {

  /**
   * Maps each pbp_play role field to the human-readable role label used in
   * ::playByPlay()'s `players` output and, client-side, in the Players
   * column/filter on the Play-by-Play Search page.
   *
   * @var string[]
   */
  const ROLE_FIELD_LABELS = [
    'pbp_passer' => 'Passer',
    'pbp_rusher' => 'Rusher',
    'pbp_receiver' => 'Receiver',
    'pbp_interceptor' => 'Interceptor',
    'pbp_sacker' => 'Sacker',
    'pbp_punter' => 'Punter',
    'pbp_kicker' => 'Kicker',
    'pbp_returner' => 'Returner',
    'pbp_blocker' => 'Blocker',
    'pbp_tackler_1' => 'Tackle',
    'pbp_tackler_2' => 'Tackle (Assist)',
    'pbp_forced_fumble_player' => 'Forced Fumble',
    'pbp_fumble_recovery_player' => 'Fumble Recovery',
    'pbp_penalized_player' => 'Penalized',
  ];

  /**
   * Maps pbp_play_type's stored values to display labels, mirroring the
   * allowed_values on \Drupal\dynasty_plays\Entity\PbpPlay::$pbp_play_type.
   *
   * @var string[]
   */
  const PLAY_TYPE_LABELS = [
    'pass' => 'Pass',
    'run' => 'Run',
    'punt' => 'Punt',
    'kickoff' => 'Kickoff',
    'field_goal' => 'Field Goal',
    'extra_point' => 'Extra Point',
    'qb_kneel' => 'QB Kneel',
    'qb_spike' => 'QB Spike',
    'no_play' => 'No Play',
    'penalty' => 'Penalty',
    'other' => 'Other',
  ];

  /**
   * Per-request memoization of ::gameContext() results, keyed by Game
   * node ID. Several rows (PlayerGameStat/Play/PbpPlay) usually share the
   * same Game -- pbp_play especially so, at ~170 plays per game -- so
   * without this, ::stats() and ::playByPlay() would recompute the same
   * season/opponent/week/URL lookups for every single row instead of once
   * per distinct game.
   *
   * @var array
   */
  protected $gameContextCache = [];

  /**
   * All published `game` nodes, flattened for client-side filtering.
   */
  public function games(): CacheableJsonResponse {
    $cache = new CacheableMetadata();
    $cache->addCacheTags(['node_list:game']);
    $cache->setCacheMaxAge(\Drupal\Core\Cache\Cache::PERMANENT);

    $nids = \Drupal::entityQuery('node')
      ->condition('type', 'game')
      ->condition('status', 1)
      ->accessCheck(TRUE)
      ->sort('field_date', 'DESC')
      ->execute();

    $team_css = DynastyHelpers::get_team_css();

    $data = [];
    foreach (Node::loadMultiple($nids) as $node) {
      $cache->addCacheableDependency($node);

      $season = (int) $node->get('field_season')->value;

      $opponent = $node->get('field_opponent')->entity;
      $week_term = $node->get('field_week')->entity;
      $coach_term = $node->get('field_opposing_coach')->entity;
      $qb = $node->get('field_starting_qb')->entity;
      $patriots_hc_term = $node->get('field_patriots_head_coach')->entity;
      $patriots_oc_term = $node->get('field_patriots_oc')->entity;
      $patriots_dc_term = $node->get('field_patriots_dc')->entity;
      $opp_oc_term = $node->get('field_opp_oc')->entity;
      $opp_dc_term = $node->get('field_opp_dc')->entity;

      foreach ([
        $opponent, $week_term, $coach_term, $qb,
        $patriots_hc_term, $patriots_oc_term, $patriots_dc_term, $opp_oc_term, $opp_dc_term,
      ] as $referenced) {
        if ($referenced) {
          $cache->addCacheableDependency($referenced);
        }
      }

      $data[] = [
        'nid' => (int) $node->id(),
        'title' => $node->label(),
        'url' => $node->toUrl()->toString(),
        'date' => $node->get('field_date')->value,
        'season' => $season,
        'week' => $week_term ? [
          'id' => (int) $week_term->id(),
          'label' => $week_term->label(),
          'weight' => (int) $week_term->getWeight(),
        ] : NULL,
        'month' => $node->get('field_month')->value,
        'weekday' => $node->get('field_weekday')->value,
        'opponent' => $opponent ? [
          'nid' => (int) $opponent->id(),
          'name' => DynastyHelpers::check_name_alts($opponent, $season),
          'css_slug' => $team_css[$opponent->id()] ?? strtolower(str_replace(' ', '-', $opponent->label())),
        ] : NULL,
        'opposing_coach' => $coach_term ? $coach_term->label() : NULL,
        'patriots_hc' => $patriots_hc_term ? $patriots_hc_term->label() : NULL,
        'patriots_oc' => $patriots_oc_term ? $patriots_oc_term->label() : NULL,
        'patriots_dc' => $patriots_dc_term ? $patriots_dc_term->label() : NULL,
        'opp_oc' => $opp_oc_term ? $opp_oc_term->label() : NULL,
        'opp_dc' => $opp_dc_term ? $opp_dc_term->label() : NULL,
        'patriots_score' => (int) $node->get('field_patriots_score')->value,
        'opponent_score' => (int) $node->get('field_opponent_score')->value,
        'score_differential' => (int) $node->get('field_score_differential')->value,
        'result' => $node->get('field_result')->value,
        'home_away' => $node->get('field_home_away')->value,
        'ot' => (bool) $node->get('field_ot')->value,
        'playoff_game' => (bool) $node->get('field_playoff_game')->value,
        'after_bye' => (bool) $node->get('field_after_bye')->value,
        'starting_qb' => $qb ? $qb->label() : NULL,
        'qb_jersey_number' => ($qb && !$qb->get('field_jersey_number')->isEmpty())
          ? (int) $qb->get('field_jersey_number')->value
          : NULL,
        'brady_attempts' => (int) $node->get('field_brady_attempts')->value,
        'brady_completions' => (int) $node->get('field_brady_completions')->value,
        'brady_yards' => (int) $node->get('field_brady_yards')->value,
        'brady_tds' => (int) $node->get('field_brady_tds')->value,
        'brady_ints' => (int) $node->get('field_brady_ints')->value,
        'passer_rating' => (float) $node->get('field_passer_rating')->value,
      ];
    }

    $response = new CacheableJsonResponse($data);
    $response->addCacheableDependency($cache);
    return $response;
  }

  /**
   * All published `highlight` nodes, flattened for client-side filtering.
   */
  public function highlights(): CacheableJsonResponse {
    $cache = new CacheableMetadata();
    $cache->addCacheTags(['node_list:highlight']);
    $cache->setCacheMaxAge(\Drupal\Core\Cache\Cache::PERMANENT);

    $nids = \Drupal::entityQuery('node')
      ->condition('type', 'highlight')
      ->condition('status', 1)
      ->accessCheck(TRUE)
      ->sort('field_season', 'DESC')
      ->execute();

    $data = [];
    foreach (Node::loadMultiple($nids) as $node) {
      $cache->addCacheableDependency($node);

      $week_term = $node->get('field_week')->entity;
      $play_type_term = $node->get('field_play_type')->entity;
      $opponent = $node->get('field_opponent')->entity;
      $game = $node->get('field_game')->entity;

      $tag_terms = [];
      foreach ($node->get('field_tag_play')->referencedEntities() as $tag) {
        $tag_terms[] = $tag->label();
        $cache->addCacheableDependency($tag);
      }

      $players = [];
      foreach ($node->get('field_players_involved')->referencedEntities() as $player) {
        $players[] = [
          'nid' => (int) $player->id(),
          'name' => $player->label(),
        ];
        $cache->addCacheableDependency($player);
      }

      foreach ([$week_term, $play_type_term, $opponent, $game] as $referenced) {
        if ($referenced) {
          $cache->addCacheableDependency($referenced);
        }
      }

      $data[] = [
        'nid' => (int) $node->id(),
        'title' => $node->label(),
        'url' => $node->toUrl()->toString(),
        'season' => (int) $node->get('field_season')->value,
        'week' => $week_term ? [
          'id' => (int) $week_term->id(),
          'label' => $week_term->label(),
          'weight' => (int) $week_term->getWeight(),
        ] : NULL,
        'opponent' => $opponent ? $opponent->label() : NULL,
        'play_type' => $play_type_term ? [
          'id' => (int) $play_type_term->id(),
          'label' => $play_type_term->label(),
        ] : NULL,
        'tag_play' => $tag_terms,
        'down' => (int) $node->get('field_down')->value,
        'distance' => (int) $node->get('field_distance')->value,
        'quarter' => (int) $node->get('field_quarter')->value,
        'minutes' => (int) $node->get('field_minutes')->value,
        'seconds' => (int) $node->get('field_seconds')->value,
        'yards_gained' => (int) $node->get('field_yards_gained')->value,
        'air_yards' => (int) $node->get('field_air_yards')->value,
        'td_scored' => (bool) $node->get('field_td_scored')->value,
        'players_involved' => $players,
        'game_title' => $game ? $game->label() : NULL,
        'muse_id' => $node->get('field_muse_video_id')->value,
        'video_file' => $node->get('field_video_file_id')->value,
      ];
    }

    $response = new CacheableJsonResponse($data);
    $response->addCacheableDependency($cache);
    return $response;
  }

  /**
   * All published Player Game Stat entities, flattened for client-side
   * filtering, sorting, and grouping on the Player Stats mode of the Play
   * Search page.
   *
   * `category` is 'Passing'/'Rushing'/'Receiving'.
   *
   * This used to also include a 'Scoring Play' category merged in from
   * `pbp_play` (filtered to `pbp_scoring_play = 1`), giving that category
   * full historical coverage instead of the one season the old `play`
   * entity covered. It moved to being an All Plays-mode-only concept (see
   * ::playByPlay(), which already exposes `pbp_scoring_play`/
   * `pbp_scoring_team`) once All Plays existed as a dedicated home for
   * browsing individual plays -- duplicating individual play rows into
   * this per-player-stat-line endpoint no longer made sense.
   *
   * Like ::playByPlay(), this queries the `player_game_stat` base table
   * directly via raw SQL rather than the Entity API: at 25,000+ rows,
   * per-entity field API overhead is expensive enough to exhaust PHP's
   * memory limit on a constrained server (confirmed in production after
   * the 1978-1999 data expansion roughly doubled this table's size). A
   * direct query for the stat columns, resolving only the much smaller set
   * of *distinct* Game/Player nodes through the Entity API (via
   * ::gameContext(), which memoizes per game), keeps this well within
   * normal memory bounds the same way it already does for ::playByPlay().
   *
   * @see \Drupal\dynasty_plays\Entity\PlayerGameStat
   */
  public function stats(): CacheableJsonResponse {
    $cache = new CacheableMetadata();
    $cache->addCacheTags(['player_game_stat_list', 'node_list:game', 'node_list:player']);
    $cache->setCacheMaxAge(\Drupal\Core\Cache\Cache::PERMANENT);

    $team_css = DynastyHelpers::get_team_css();

    $rows = \Drupal::database()->select('player_game_stat', 's')
      ->fields('s', [
        'id', 'stat_game', 'stat_player_name', 'stat_player', 'stat_quarter', 'stat_category',
        'stat_completions', 'stat_attempts', 'stat_pass_yards', 'stat_interceptions', 'stat_pass_td',
        'stat_carries', 'stat_rush_yards', 'stat_rush_td',
        'stat_targets', 'stat_receptions', 'stat_rec_yards', 'stat_rec_td',
      ])
      ->condition('status', 1)
      ->orderBy('id', 'ASC')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);

    $game_ids = array_unique(array_filter(array_column($rows, 'stat_game')));
    $games = $game_ids ? Node::loadMultiple($game_ids) : [];

    $player_ids = array_unique(array_filter(array_column($rows, 'stat_player')));
    $players = $player_ids ? Node::loadMultiple($player_ids) : [];

    $int_fields = [
      'stat_completions', 'stat_attempts', 'stat_pass_yards', 'stat_interceptions', 'stat_pass_td',
      'stat_carries', 'stat_rush_yards', 'stat_rush_td',
      'stat_targets', 'stat_receptions', 'stat_rec_yards', 'stat_rec_td',
    ];

    $data = [];
    foreach ($rows as $row) {
      $game = $games[$row['stat_game']] ?? NULL;
      if (!$game) {
        continue;
      }

      $player = $players[$row['stat_player']] ?? NULL;
      if ($player) {
        $cache->addCacheableDependency($player);
      }

      $stat_row = $this->gameContext($game, $cache, $team_css) + [
        'id' => 'stat-' . $row['id'],
        'player_name' => $row['stat_player_name'],
        'player' => $player ? [
          'nid' => (int) $player->id(),
          'name' => $player->label(),
        ] : NULL,
        'quarter' => $row['stat_quarter'],
        'category' => $row['stat_category'],
      ];
      foreach ($int_fields as $field) {
        $stat_row[substr($field, 5)] = $row[$field] !== NULL ? (int) $row[$field] : NULL;
      }
      $data[] = $stat_row;
    }

    $response = new CacheableJsonResponse($data);
    $response->addCacheableDependency($cache);
    return $response;
  }

  /**
   * All published Play-by-Play entries (raw per-play log, 1978-present),
   * flattened for client-side filtering/searching on the Play-by-Play
   * Search page. Response shape is `{players, play_type_labels, rows}`,
   * not a bare array -- see the `players`/`rows` normalization below.
   *
   * @see \Drupal\dynasty_plays\Entity\PbpPlay
   */
  public function playByPlay(): CacheableJsonResponse {
    $cache = new CacheableMetadata();
    $cache->addCacheTags(['pbp_play_list', 'node_list:game', 'node_list:player']);
    $cache->setCacheMaxAge(\Drupal\Core\Cache\Cache::PERMANENT);

    $team_css = DynastyHelpers::get_team_css();
    $database = \Drupal::database();
    $role_fields = array_keys(self::ROLE_FIELD_LABELS);

    // At ~135,000 rows (vs. a few hundred games and roughly 900 distinct
    // players), loading every row through the Entity API -- as the other
    // endpoints in this class do -- takes over a minute: per-entity field
    // API overhead dominates when it's repeated tens of thousands of
    // times. A direct query for the per-row field values, resolving only
    // the small *distinct* sets of Game/Player/Highlight nodes through
    // the Entity API (via ::gameContext(), which memoizes per game),
    // cuts this from minutes to well under a second.
    //
    // The dataset has grown past what that comment's "~30,000-row"
    // history (and the module's own older "~61,000 rows" comments
    // elsewhere) were sized against -- 134,095 rows today, having
    // expanded to cover 1978-present -- and this endpoint's response
    // going through Drupal's own page/dynamic-page cache (Redis here) on
    // top of building it turned out to add substantial memory overhead
    // of its own. Measured on this codebase's actual data before any of
    // the choices below: the *original* per-row-game-context version of
    // this method (no play_type/role fields at all) already peaked
    // around 680MB building an ~90MB response, and reliably hit PHP's
    // memory_limit once Drupal's cache write was included -- a
    // pre-existing risk from data growth, not something introduced by
    // adding play_type/role fields (confirmed: adding all 14 role fields
    // costs comparatively little next to this). So, beyond keeping the
    // established raw-SQL-over-Entity-API pattern: (1) the distinct
    // Game/Player/Highlight ID sets are found with small, cheap `SELECT
    // DISTINCT` queries of their own instead of by scanning a fully
    // materialized copy of every row in PHP; (2) the main query is
    // iterated as a forward cursor (`foreach` over the executed
    // statement) straight into $data, rather than pulled into one big
    // array via ->fetchAll() and then looped a second time, so this
    // never holds two ~135,000-row PHP structures (a raw copy and a
    // transformed copy) at once; and (3) both games and players are
    // normalized into their own small dictionaries (~780 games, ~900
    // players) sent once, with rows referencing them by ID, instead of
    // repeating a game's title/url/season/week/opponent on every one of
    // its ~170 plays or a player's name on every role they appear in --
    // by far the biggest win, since game/player context was always the
    // largest redundant part of this response, not the new fields. This
    // combination measures at ~540MB peak building a ~62MB response,
    // comfortably inside production's 900M PHP-FPM budget with real
    // margin for further data growth -- don't reintroduce ->fetchAll()
    // or per-row game/player denormalization here without re-measuring.
    $game_ids = array_unique(array_filter($database->select('pbp_play', 'p')
      ->fields('p', ['pbp_game'])
      ->condition('status', 1)
      ->distinct()
      ->execute()
      ->fetchCol()));
    $games = $game_ids ? Node::loadMultiple($game_ids) : [];

    $player_id_lists = [];
    foreach (array_merge(['pbp_player'], $role_fields) as $field) {
      $player_id_lists[] = $database->select('pbp_play', 'p')
        ->fields('p', [$field])
        ->condition('status', 1)
        ->isNotNull($field)
        ->distinct()
        ->execute()
        ->fetchCol();
    }
    $player_ids = array_unique(array_filter(array_merge(...$player_id_lists)));
    $players = $player_ids ? Node::loadMultiple($player_ids) : [];

    // A flat nid => name dictionary, sent once at the top of the response
    // instead of repeating each player's name on every row/role they
    // appear in (up to ~146,000 role occurrences across ~135,000 rows) --
    // rows below reference players by nid only. Cache dependencies are
    // added once per distinct player here too, rather than once per
    // row-occurrence.
    $player_names = [];
    foreach ($players as $nid => $player) {
      $cache->addCacheableDependency($player);
      $player_names[$nid] = $player->label();
    }

    // Same batch pattern for the (currently very small) set of manually
    // curated highlight links.
    $highlight_ids = array_unique(array_filter($database->select('pbp_play', 'p')
      ->fields('p', ['pbp_highlight'])
      ->condition('status', 1)
      ->isNotNull('pbp_highlight')
      ->distinct()
      ->execute()
      ->fetchCol()));
    $highlights = $highlight_ids ? Node::loadMultiple($highlight_ids) : [];
    foreach ($highlights as $highlight) {
      $cache->addCacheableDependency($highlight);
    }

    $result = $database->select('pbp_play', 'p')
      ->fields('p', array_merge([
        'id', 'pbp_game', 'pbp_sequence', 'pbp_quarter', 'pbp_time', 'pbp_down',
        'pbp_distance', 'pbp_location', 'pbp_patriots_score', 'pbp_opponent_score',
        'pbp_detail__value', 'pbp_epb', 'pbp_epa', 'pbp_source_url', 'pbp_player',
        'pbp_scoring_play', 'pbp_scoring_team', 'pbp_highlight', 'pbp_play_type',
      ], $role_fields))
      ->condition('status', 1)
      ->orderBy('id', 'ASC')
      ->execute();
    $result->setFetchMode(\PDO::FETCH_ASSOC);

    // A per-game dictionary, keyed by Game node ID, sent once instead of
    // repeating each game's title/url/season/week/opponent on every one
    // of its ~380 plays -- rows below reference their game by `game_nid`
    // only. This is the biggest single win of the normalization here:
    // game context (not the new role data) was always the largest
    // redundant chunk of this response, since it's ~9 fields repeated
    // per-row for only ~350 distinct games.
    $games_out = [];
    foreach ($games as $nid => $game) {
      $games_out[$nid] = $this->gameContext($game, $cache, $team_css);
      unset($games_out[$nid]['game_nid']);
    }

    $data = [];
    foreach ($result as $row) {
      if (!isset($games_out[$row['pbp_game']])) {
        continue;
      }

      $highlight = $highlights[$row['pbp_highlight']] ?? NULL;

      // Every role field set on this row, as compact [nid, role] pairs --
      // names are looked up client-side against the `players` dictionary
      // above, not repeated here. E.g. a pass_complete play carries both
      // a Passer and a Receiver; a sack-with-fumble carries a Passer, a
      // Sacker, and a Fumble Recovery. See PbpPlay::baseFieldDefinitions()
      // for why a play can need several of these at once.
      $row_players = [];
      foreach (self::ROLE_FIELD_LABELS as $field => $role_label) {
        if (!empty($row[$field]) && isset($player_names[$row[$field]])) {
          $row_players[] = [(int) $row[$field], $role_label];
        }
      }

      $data[] = [
        'id' => (int) $row['id'],
        'game_nid' => (int) $row['pbp_game'],
        'sequence' => (int) $row['pbp_sequence'],
        'quarter' => $row['pbp_quarter'],
        'time' => $row['pbp_time'],
        'down' => $row['pbp_down'] !== NULL ? (int) $row['pbp_down'] : NULL,
        'distance' => $row['pbp_distance'] !== NULL ? (int) $row['pbp_distance'] : NULL,
        'location' => $row['pbp_location'],
        'patriots_score' => $row['pbp_patriots_score'] !== NULL ? (int) $row['pbp_patriots_score'] : NULL,
        'opponent_score' => $row['pbp_opponent_score'] !== NULL ? (int) $row['pbp_opponent_score'] : NULL,
        'detail' => $row['pbp_detail__value'],
        'epb' => $row['pbp_epb'] !== NULL ? (float) $row['pbp_epb'] : NULL,
        'epa' => $row['pbp_epa'] !== NULL ? (float) $row['pbp_epa'] : NULL,
        'source_url' => $row['pbp_source_url'],
        'player' => $row['pbp_player'] !== NULL ? (int) $row['pbp_player'] : NULL,
        'scoring_play' => (bool) $row['pbp_scoring_play'],
        'scoring_team' => $row['pbp_scoring_team'],
        'highlight_url' => $highlight ? $highlight->toUrl()->toString() : NULL,
        'play_type' => $row['pbp_play_type'],
        'players' => $row_players,
      ];
    }

    $response = new CacheableJsonResponse([
      'games' => $games_out,
      'players' => $player_names,
      'play_type_labels' => self::PLAY_TYPE_LABELS,
      'rows' => $data,
    ]);
    $response->addCacheableDependency($cache);
    return $response;
  }

  /**
   * Builds the shared game-context fields (season, week, opponent, etc.)
   * used by ::stats() and ::playByPlay(), so they stay identical no matter
   * which entity type (PlayerGameStat, Play, or PbpPlay) a row came from.
   */
  private function gameContext($game, CacheableMetadata $cache, array $team_css): array {
    $cache->addCacheableDependency($game);

    $nid = (int) $game->id();
    if (isset($this->gameContextCache[$nid])) {
      return $this->gameContextCache[$nid];
    }

    $season = (int) $game->get('field_season')->value;
    $opponent = $game->get('field_opponent')->entity;
    $week_term = $game->get('field_week')->entity;
    foreach ([$opponent, $week_term] as $referenced) {
      if ($referenced) {
        $cache->addCacheableDependency($referenced);
      }
    }

    return $this->gameContextCache[$nid] = [
      'game_nid' => (int) $game->id(),
      'game_title' => $game->label(),
      'game_url' => $game->toUrl()->toString(),
      'season' => $season,
      'week' => $week_term ? [
        'id' => (int) $week_term->id(),
        'label' => $week_term->label(),
        'weight' => (int) $week_term->getWeight(),
      ] : NULL,
      'opponent' => $opponent ? [
        'nid' => (int) $opponent->id(),
        'name' => DynastyHelpers::check_name_alts($opponent, $season),
        'css_slug' => $team_css[$opponent->id()] ?? strtolower(str_replace(' ', '-', $opponent->label())),
      ] : NULL,
      'home_away' => $game->get('field_home_away')->value,
      'playoff_game' => (bool) $game->get('field_playoff_game')->value,
      'result' => $game->get('field_result')->value,
    ];
  }

}
