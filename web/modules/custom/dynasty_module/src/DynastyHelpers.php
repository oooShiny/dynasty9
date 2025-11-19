<?php

namespace Drupal\dynasty_module;

use Drupal\node\Entity\Node;

class DynastyHelpers {

  /**
   * Get passer rating based off passing attributes.
   * Attempts
   * @param $att
   * Completions
   * @param $comp
   * Passing Yards
   * @param $yds
   * Passing TDs
   * @param $td
   * Interceptions
   * @param $int
   * @return float|int
   */
  public static function passer_rating($att, $comp, $yds, $td, $int) {
    $a = (($comp/$att) - 0.3) * 5;
    $b = (($yds/$att) - 3) * .25;
    $c = ($td/$att) * 20;
    $d = 2.375 - (($int/$att) * 25);

    $passer_rating = (($a + $b + $c + $d) / 6) * 100;

    return $passer_rating;
  }

  public static function win_pct($w, $l, $t) {
    return $pct = $w / ($w + $l + $t);
  }

  public static function get_teams($data = FALSE) {
    $teams = [];
    $nids = \Drupal::entityQuery('node')->accessCheck(TRUE)->condition('type','team')->execute();
    $nodes =  Node::loadMultiple($nids);
    $divs = self::get_term_names('division');
    $confs = self::get_term_names('conference');

    foreach ($nodes as $team) {
      $title = explode(' ', $team->getTitle());
      if ($data === FALSE) {
        $teams[$team->id()] = array_pop($title);
      }
      else {
        $teams[$team->id()] = [
          'name' => array_pop($title),
          'div' => $divs[$team->get('field_division')->target_id],
          'conf' => $confs[$team->get('field_conference')->target_id],
        ];
      }
    }
    return $teams;
  }

  public static function get_team_css() {
    $teams = [];
    $nids = \Drupal::entityQuery('node')->accessCheck(TRUE)->condition('type','team')->execute();
    $nodes =  Node::loadMultiple($nids);

    foreach ($nodes as $team) {
      $title = str_replace(' ', '-', $team->getTitle());
      $teams[$team->id()] = strtolower($title);
    }
    return $teams;
  }

  public static function get_players() {
    $players = [];
    $nids = \Drupal::entityQuery('node')->accessCheck(TRUE)->condition('type','player')->execute();
    $nodes =  Node::loadMultiple($nids);
    $positions = DynastyHelpers::get_positions();
    foreach ($nodes as $player) {
      $pos = $player->get('field_player_position')->target_id;
      $players[$player->id()] = [
        'name' => $player->getTitle(),
        'position' => $positions[$pos],
      ];
    }
    return $players;
  }

  public static function get_positions() {
    $positions = [];
    $terms =\Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadTree('position');
    foreach ($terms as $term) {
      $positions[$term->tid] = $term->name;
    }
    return $positions;
  }

  public static function get_weeks() {
    $weeks = [];
    $terms =\Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadTree('week');
    foreach ($terms as $term) {
      $weeks[$term->tid] = $term->name;
    }
    return $weeks;
  }

  public static function get_week_number($week_name, $season) {
    if ($season >= '2021') {
      $weeks = [
        'Week 1' => 1,
        'Week 2' => 2,
        'Week 3' => 3,
        'Week 4' => 4,
        'Week 5' => 5,
        'Week 6' => 6,
        'Week 7' => 7,
        'Week 8' => 8,
        'Week 9' => 9,
        'Week 10' => 10,
        'Week 11' => 11,
        'Week 12' => 12,
        'Week 13' => 13,
        'Week 14' => 14,
        'Week 15' => 15,
        'Week 16' => 16,
        'Week 17' => 17,
        'Week 18' => 18,
        'AFC Wildcard' => 19,
        'AFC Divisional Round' => 20,
        'AFC Conference Championship' => 21,
        'Super Bowl' => 22,
      ];
    }
    else {
      $weeks = [
        'Week 1' => 1,
        'Week 2' => 2,
        'Week 3' => 3,
        'Week 4' => 4,
        'Week 5' => 5,
        'Week 6' => 6,
        'Week 7' => 7,
        'Week 8' => 8,
        'Week 9' => 9,
        'Week 10' => 10,
        'Week 11' => 11,
        'Week 12' => 12,
        'Week 13' => 13,
        'Week 14' => 14,
        'Week 15' => 15,
        'Week 16' => 16,
        'Week 17' => 17,
        'AFC Wildcard' => 18,
        'AFC Divisional Round' => 19,
        'AFC Conference Championship' => 20,
        'Super Bowl' => 21,
      ];
    }

    return $weeks[$week_name];
  }

  public static function get_term_names($taxonomy) {
    $term_names = [];
    $terms =\Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadTree($taxonomy);
    foreach ($terms as $term) {
      $term_names[$term->tid] = $term->name;
    }
    return $term_names;
  }
}
