<?php

namespace Drupal\dynasty_module\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\node\Entity\Node;
/**
 * Provides a 'Hello' Block.
 *
 * @Block(
 *   id = "brady_total_tds",
 *   admin_label = @Translation("Brady Total TDs"),
 *   category = @Translation("Dynasty"),
 * )
 */
class BradyTdTotals extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    $player_tds = [];
    $all_tds = [];
    $players = $this->get_players();
    $teams = $this->get_teams();
    $weeks = $this->get_weeks();
    // Get all Gif paragraphs tagged with Brady + Pass + TD.
    $nids = \Drupal::entityQuery('node')
      ->condition('type', 'highlight')
      ->condition('field_players_involved', '272')
      ->condition('field_td_scored', TRUE)
      ->condition('field_play_type', '54')
      ->sort('field_season' , 'DESC')
      ->execute();
    $node_stirage = \Drupal::entityTypeManager()->getStorage('node');
    $gif_nodes = $node_stirage->loadMultiple($nids);
    // TDs sorted by player.
    foreach ($gif_nodes as $gif) {
      $players_involved = $gif->get('field_players_involved')->getValue();
      // Only count plays where Brady was the one who threw the TD.
      if ($players_involved[count($players_involved) - 2]['target_id'] == '272') {
        $receiver = array_pop($players_involved);
        $name = $players[$receiver['target_id']]['name'];
        $position = $players[$receiver['target_id']]['position'];
        $week = $weeks[$gif->get('field_week')->target_id];
        $player_tds[$name][] = [
          'title' => $gif->label(),
          'link' => 'https://gfycat.com/' . $gif->get('field_gfycat_id')->value,
          'season' => $gif->get('field_season')->value,
          'week' => $week,
          'opp' => $teams[$gif->get('field_opponent')->target_id],
        ];

        $position_tds[$position][] = [
          'title' => $gif->label(),
          'link' => 'https://gfycat.com/' . $gif->get('field_gfycat_id')->value,
          'season' => $gif->get('field_season')->value,
          'week' => $week,
          'opp' => $teams[$gif->get('field_opponent')->target_id],
        ];
        $all_tds[$gif->get('field_season')->value][$week][] = [
          'title' => $gif->label(),
          'link' => 'https://gfycat.com/' . $gif->get('field_gfycat_id')->value,
          'opp' => $teams[$gif->get('field_opponent')->target_id],
          'week' => $week,
        ];
      }
    }
    array_multisort(array_map('count', $player_tds), SORT_DESC, $player_tds);
    array_multisort(array_map('count', $position_tds), SORT_DESC, $position_tds);
    $ordered_games = [];
    foreach ($all_tds as $season => $weeks) {
      krsort($weeks, SORT_NATURAL);
      $ordered_games[$season] = $weeks;
    }
    $metadata = [];
    foreach ($player_tds as $player => $gifs) {
      $metadata[] = [
        'player' => $player,
        'count' => count($gifs)
      ];
    }

    // TDs sorted by quarter.
    $quarter_tds = [
      '1' => [],
      '2' => [],
      '3' => [],
      '4' => [],
      'OT' => []
    ];
    foreach ($gif_nodes as $gif) {
      if (!$gif->get('field_quarter')->isEmpty()) {
        $quarter = $gif->get('field_quarter')->value;
        if ($quarter == 5) {
          $quarter = 'OT';
        }
        $quarter_tds[$quarter][] = [
          'title' => $gif->label(),
          'link' => 'https://gfycat.com/' . $gif->get('field_gfycat_id')->value,
          'season' => $gif->get('field_season')->value,
          'opp' => $teams[$gif->get('field_opponent')->target_id],
        ];
      }
    }
    $q_count = [];
    foreach ($quarter_tds as $quarter => $gifs) {
      $q_count[$quarter] = count($gifs);
    }
    $quarter_tds['1st Quarter'] = $quarter_tds[1];
    $quarter_tds['2nd Quarter'] = $quarter_tds[2];
    $quarter_tds['3rd Quarter'] = $quarter_tds[3];
    $quarter_tds['4th Quarter'] = $quarter_tds[4];
    $quarter_tds['Overtime'] = $quarter_tds['OT'];
    unset($quarter_tds[1]);
    unset($quarter_tds[2]);
    unset($quarter_tds[3]);
    unset($quarter_tds[4]);
    unset($quarter_tds['OT']);

    // TDs sorted by distance.
    $yards_tds = [];
    foreach ($gif_nodes as $gif) {
      if (!$gif->get('field_yards_gained')->isEmpty()) {
        $yards = $gif->get('field_yards_gained')->value;
        $yards_tds[$yards][] = [
          'title' => $gif->label(),
          'link' => 'https://gfycat.com/' . $gif->get('field_gfycat_id')->value,
          'season' => $gif->get('field_season')->value,
          'opp' => $teams[$gif->get('field_opponent')->target_id],
        ];
      }
    }
    krsort($yards_tds);



    return [
      '#theme' => 'brady_total_tds',
      '#players' => $player_tds,
      '#playercount' => $metadata,
      '#quarters' => $quarter_tds,
      '#q_count' => $q_count,
      '#tdyards' => $yards_tds,
      '#tdposition' => $position_tds,
      '#alltds' => $ordered_games
    ];
  }

  /**
   * Helper to get array of players.
   */
  function get_players() {
    $players = [];
    $nids = \Drupal::entityQuery('node')->condition('type','player')->execute();
    $nodes =  Node::loadMultiple($nids);
    $positions = $this->get_positions();
    foreach ($nodes as $player) {
      $pos = $player->get('field_player_position')->target_id;
      $players[$player->id()] = [
        'name' => $player->getTitle(),
        'position' => $positions[$pos],
      ];
    }
    return $players;
  }

  function get_teams() {
    $teams = [];
    $nids = \Drupal::entityQuery('node')->condition('type','team')->execute();
    $nodes =  Node::loadMultiple($nids);

    foreach ($nodes as $team) {
      $title = explode(' ', $team->getTitle());
      $teams[$team->id()] = array_pop($title);
    }
    return $teams;
  }

  function get_positions() {
    $positions = [];
    $terms =\Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadTree('position');
    foreach ($terms as $term) {
      $positions[$term->tid] = $term->name;
    }
    return $positions;
  }

  function get_weeks() {
    $weeks = [];
    $terms =\Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadTree('week');
    foreach ($terms as $term) {
      $weeks[$term->tid] = $term->name;
    }
    return $weeks;
  }
}
