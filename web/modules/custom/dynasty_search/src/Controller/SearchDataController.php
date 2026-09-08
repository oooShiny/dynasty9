<?php

namespace Drupal\dynasty_search\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\dynasty_module\DynastyHelpers;
use Drupal\node\Entity\Node;

/**
 * JSON data endpoints backing the Game Search and Play Search pages.
 *
 * These replace the old Solr/Search API-backed game_search and
 * highlight_search views: the data sets are small and bounded, so instead
 * of indexing them the whole (published) data set is served as flat JSON
 * once and filtered/sorted/paginated client-side.
 */
class SearchDataController extends ControllerBase {

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
   * All published `highlight` (play) nodes, flattened for client-side
   * filtering.
   */
  public function plays(): CacheableJsonResponse {
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
        $players[] = $player->label();
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

}
