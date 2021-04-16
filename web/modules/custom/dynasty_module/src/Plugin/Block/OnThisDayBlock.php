<?php

namespace Drupal\dynasty_module\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\node\Entity\Node;

/**
 * Provides a Block that displays games/events that happened on the current date.
 *
 * @Block(
 *   id = "on_this_day_block",
 *   admin_label = @Translation("On This Day Block"),
 *   category = @Translation("Dynasty"),
 * )
 */
class OnThisDayBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    // Get all games and see if the day/month matches up to today.
    $today = date('m-d');
    $game_nids = \Drupal::entityQuery('node')
      ->condition('type', 'game')
      ->execute();

    $games = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadMultiple($game_nids);

    $build = [
      '#theme' => 'on_this_day_block',
      '#games' => []
    ];
    foreach ($games as $game) {
      $game_date = substr($game->get('field_date')->value, 5);
      if ($game_date == $today) {
        $render_controller = \Drupal::entityTypeManager()->getViewBuilder($game->getEntityTypeId());
        $build['#games'][] = $render_controller->view($game, 'card');
      }
    }
    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    // Don't cache this block, otherwise it shows the wrong date.
    return 0;
  }
}
