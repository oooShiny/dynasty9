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
   * All published Player Game Stat entities *and* scoring Play-by-Play
   * entries, flattened onto one shared row shape for client-side
   * filtering, sorting, and grouping on the Stat Finder page.
   *
   * The two sources describe different things (a per-quarter stat line vs.
   * a single scoring play), so most fields only apply to one or the other
   * -- each row carries every field, left NULL/blank where not applicable,
   * the same way an individual PlayerGameStat row already leaves the 8
   * stat columns from other categories NULL. `category` is
   * 'Passing'/'Rushing'/'Receiving' for stat lines and 'Scoring Play' for
   * pbp_play entries, so the existing Category filter doubles as the
   * row-kind switch.
   *
   * The 'Scoring Play' rows used to come from the (now-retired) `play`
   * entity, which only ever covered one season (2006), hand-curated. They
   * now come from `pbp_play` filtered to `pbp_scoring_play = 1` -- a
   * reliable score-delta computed at import time -- giving this category
   * full historical coverage (1978-2023) instead of one season.
   * `pbp_play` has no `turnover` signal (no reliable way to derive it from
   * play text), so that column is always NULL for these rows now; the
   * frontend already tolerates a null turnover value.
   *
   * @see \Drupal\dynasty_plays\Entity\PlayerGameStat
   * @see \Drupal\dynasty_plays\Entity\PbpPlay
   */
  public function stats(): CacheableJsonResponse {
    $cache = new CacheableMetadata();
    $cache->addCacheTags([
      'player_game_stat_list', 'pbp_play_list', 'node_list:game', 'node_list:player', 'node_list:highlight',
    ]);
    $cache->setCacheMaxAge(\Drupal\Core\Cache\Cache::PERMANENT);

    $team_css = DynastyHelpers::get_team_css();
    $data = [];

    $stat_storage = $this->entityTypeManager()->getStorage('player_game_stat');
    $stat_ids = $stat_storage->getQuery()
      ->condition('status', 1)
      ->accessCheck(TRUE)
      ->execute();

    // Loaded in slices to keep peak memory bounded; the (small) set of
    // distinct Game/Player nodes referenced stays in the entity static
    // cache across slices, so this doesn't repeat those loads.
    foreach (array_chunk($stat_ids, 500) as $slice) {
      foreach ($stat_storage->loadMultiple($slice) as $stat) {
        $cache->addCacheableDependency($stat);

        $game = $stat->get('stat_game')->entity;
        if (!$game) {
          continue;
        }

        $player = $stat->get('stat_player')->entity;
        if ($player) {
          $cache->addCacheableDependency($player);
        }

        $data[] = $this->gameContext($game, $cache, $team_css) + [
          'id' => 'stat-' . $stat->id(),
          'player_name' => $stat->get('stat_player_name')->value,
          'player' => $player ? [
            'nid' => (int) $player->id(),
            'name' => $player->label(),
          ] : NULL,
          'quarter' => $stat->get('stat_quarter')->value,
          'category' => $stat->get('stat_category')->value,
          'completions' => $this->intOrNull($stat, 'stat_completions'),
          'attempts' => $this->intOrNull($stat, 'stat_attempts'),
          'pass_yards' => $this->intOrNull($stat, 'stat_pass_yards'),
          'interceptions' => $this->intOrNull($stat, 'stat_interceptions'),
          'pass_td' => $this->intOrNull($stat, 'stat_pass_td'),
          'carries' => $this->intOrNull($stat, 'stat_carries'),
          'rush_yards' => $this->intOrNull($stat, 'stat_rush_yards'),
          'rush_td' => $this->intOrNull($stat, 'stat_rush_td'),
          'targets' => $this->intOrNull($stat, 'stat_targets'),
          'receptions' => $this->intOrNull($stat, 'stat_receptions'),
          'rec_yards' => $this->intOrNull($stat, 'stat_rec_yards'),
          'rec_td' => $this->intOrNull($stat, 'stat_rec_td'),
          'distance' => NULL,
          'scoring_team' => NULL,
          'turnover' => NULL,
          'description' => NULL,
          'highlight_url' => NULL,
        ];
      }
    }

    $pbp_storage = $this->entityTypeManager()->getStorage('pbp_play');
    $pbp_ids = $pbp_storage->getQuery()
      ->condition('status', 1)
      ->condition('pbp_scoring_play', 1)
      ->accessCheck(TRUE)
      ->execute();

    foreach (array_chunk($pbp_ids, 500) as $slice) {
      foreach ($pbp_storage->loadMultiple($slice) as $pbp) {
        $cache->addCacheableDependency($pbp);

        $game = $pbp->get('pbp_game')->entity;
        if (!$game) {
          continue;
        }

        $player = $pbp->get('pbp_player')->entity;
        if ($player) {
          $cache->addCacheableDependency($player);
        }

        $highlight = $pbp->get('pbp_highlight')->entity;
        $highlight_url = NULL;
        if ($highlight) {
          $cache->addCacheableDependency($highlight);
          $highlight_url = $highlight->toUrl()->toString();
        }

        $data[] = $this->gameContext($game, $cache, $team_css) + [
          'id' => 'pbp-' . $pbp->id(),
          'player_name' => $player ? $player->label() : NULL,
          'player' => $player ? [
            'nid' => (int) $player->id(),
            'name' => $player->label(),
          ] : NULL,
          'quarter' => $pbp->get('pbp_quarter')->value,
          'category' => 'Scoring Play',
          'completions' => NULL,
          'attempts' => NULL,
          'pass_yards' => NULL,
          'interceptions' => NULL,
          'pass_td' => NULL,
          'carries' => NULL,
          'rush_yards' => NULL,
          'rush_td' => NULL,
          'targets' => NULL,
          'receptions' => NULL,
          'rec_yards' => NULL,
          'rec_td' => NULL,
          'distance' => $this->intOrNull($pbp, 'pbp_distance'),
          'scoring_team' => $pbp->get('pbp_scoring_team')->value ?: NULL,
          'turnover' => NULL,
          'description' => $pbp->get('pbp_detail')->value ?: NULL,
          'highlight_url' => $highlight_url,
        ];
      }
    }

    $response = new CacheableJsonResponse($data);
    $response->addCacheableDependency($cache);
    return $response;
  }

  /**
   * All published Play-by-Play entries (raw per-play log, 1978-1999),
   * flattened for client-side filtering/searching on the Play-by-Play
   * Search page.
   *
   * @see \Drupal\dynasty_plays\Entity\PbpPlay
   */
  public function playByPlay(): CacheableJsonResponse {
    $cache = new CacheableMetadata();
    $cache->addCacheTags(['pbp_play_list', 'node_list:game', 'node_list:player']);
    $cache->setCacheMaxAge(\Drupal\Core\Cache\Cache::PERMANENT);

    $team_css = DynastyHelpers::get_team_css();

    // At ~61,000 rows (vs. a few hundred games), loading every row through
    // the Entity API -- as the other endpoints in this class do -- takes
    // over a minute: per-entity field API overhead dominates when it's
    // repeated tens of thousands of times. A direct query for the ~170
    // plays-per-game field values, resolving only the ~350 *distinct*
    // Game nodes through the Entity API (via ::gameContext(), which
    // memoizes per game), cuts this from minutes to well under a second.
    $rows = \Drupal::database()->select('pbp_play', 'p')
      ->fields('p', [
        'id', 'pbp_game', 'pbp_sequence', 'pbp_quarter', 'pbp_time', 'pbp_down',
        'pbp_distance', 'pbp_location', 'pbp_patriots_score', 'pbp_opponent_score',
        'pbp_detail__value', 'pbp_epb', 'pbp_epa', 'pbp_source_url', 'pbp_player',
        'pbp_scoring_play', 'pbp_scoring_team', 'pbp_highlight',
      ])
      ->condition('status', 1)
      ->orderBy('id', 'ASC')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);

    $game_ids = array_unique(array_filter(array_column($rows, 'pbp_game')));
    $games = $game_ids ? Node::loadMultiple($game_ids) : [];

    // Batch-load only the distinct Player nodes actually referenced, same
    // pattern as $games above -- cheap even at ~61,000 rows since the
    // number of distinct players involved is small.
    $player_ids = array_unique(array_filter(array_column($rows, 'pbp_player')));
    $players = $player_ids ? Node::loadMultiple($player_ids) : [];

    // Same batch pattern for the (currently very small) set of manually
    // curated highlight links.
    $highlight_ids = array_unique(array_filter(array_column($rows, 'pbp_highlight')));
    $highlights = $highlight_ids ? Node::loadMultiple($highlight_ids) : [];

    $data = [];
    foreach ($rows as $row) {
      $game = $games[$row['pbp_game']] ?? NULL;
      if (!$game) {
        continue;
      }

      $player = $players[$row['pbp_player']] ?? NULL;
      if ($player) {
        $cache->addCacheableDependency($player);
      }

      $highlight = $highlights[$row['pbp_highlight']] ?? NULL;
      if ($highlight) {
        $cache->addCacheableDependency($highlight);
      }

      $data[] = $this->gameContext($game, $cache, $team_css) + [
        'id' => (int) $row['id'],
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
        'player' => $player ? ['nid' => (int) $player->id(), 'name' => $player->label()] : NULL,
        'scoring_play' => (bool) $row['pbp_scoring_play'],
        'scoring_team' => $row['pbp_scoring_team'],
        'highlight_url' => $highlight ? $highlight->toUrl()->toString() : NULL,
      ];
    }

    $response = new CacheableJsonResponse($data);
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

  /**
   * Reads an integer field's value, preserving NULL (as opposed to the
   * (int) cast elsewhere in this controller, which would turn NULL into 0
   * for fields that are legitimately not applicable to a given stat row).
   */
  private function intOrNull($entity, string $field_name): ?int {
    return $entity->get($field_name)->isEmpty() ? NULL : (int) $entity->get($field_name)->value;
  }

}
