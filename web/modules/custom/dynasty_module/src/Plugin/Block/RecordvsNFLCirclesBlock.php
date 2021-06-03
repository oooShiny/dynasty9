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
    $teams = DynastyHelpers::get_teams();
    foreach ($games as $game) {
      $records[] = '';
    }


    return [
      '#theme' => 'record_vs_nfl_block',

    ];
  }


}
