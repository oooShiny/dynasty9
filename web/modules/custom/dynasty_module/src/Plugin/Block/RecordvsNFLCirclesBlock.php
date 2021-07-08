<?php

namespace Drupal\dynasty_module\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\node\Entity\Node;
use Drupal\dynasty_module\DynastyHelpers;
/**
 * Block that displays filterable/sortable NE's record vs each team.
 *
 * @Block(
 *   id = "record_vs_nfl",
 *   admin_label = @Translation("Record vs NFL"),
 *   category = @Translation("Dynasty"),
 * )
 */
class RecordvsNFLCirclesBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    // Get all game win/loss data.
    $game_nids = \Drupal::entityQuery('node')
      ->condition('type', 'game')
      ->execute();

    $games = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadMultiple($game_nids);
    $records = [];
    $team_css = DynastyHelpers::get_team_css();
    $teams = DynastyHelpers::get_teams(TRUE);
    foreach ($games as $game) {
      $opp = $game->get('field_opponent')->target_id;
      $css = $team_css[$opp];
      if (!isset($records[$opp])) {
        $records[$opp] = [
          'name' => $teams[$opp]['name'],
          'div' => strtolower($teams[$opp]['div']),
          'conf' => strtolower($teams[$opp]['conf']),
          'css' => $css,
          'w' => 0,
          'l' => 0,
          'pct' => .000
        ];
      }
      $result = strtolower($game->get('field_result')->value);
      if ($result == 'win') {
        $records[$opp]['w'] += 1;
      }
      else {
        $records[$opp]['l'] += 1;
      }
      $records[$opp]['pct'] = DynastyHelpers::win_pct($records[$opp]['w'], $records[$opp]['l'], 0);
    }


    return [
      '#theme' => 'record_vs_nfl_block',
      '#records' => $records,
    ];
  }

}
