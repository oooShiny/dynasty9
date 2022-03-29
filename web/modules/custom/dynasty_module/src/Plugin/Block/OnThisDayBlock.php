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
      ->loadMultiple(array_keys($game_nids));

    $build = [
      '#theme' => 'on_this_day_block',
      '#games' => []
    ];
    foreach ($games as $game) {
      $game_date = '';
      if ($game->field_date->hasField() && !$game->field_date->isEmpty()) {
        $game_date = substr($game->field_date->value, 5);
      }
      if ($game_date !== '' && $game_date == $today) {
        // Find highlights for this game.
        $highlight_nid = \Drupal::entityQuery('node')
          ->condition('type', 'highlight')
          ->condition('field_play_of_the_game', 1)
          ->condition('field_game', $game->id())
          ->execute();

        $highlight = \Drupal::entityTypeManager()
          ->getStorage('node')
          ->load(end($highlight_nid));

        $opponent = explode(' ', $game->field_opponent->entity->label());
        $css = strtolower(implode('-', $opponent));
        $muse_id = $highlight->field_muse_video_id->value ?? '';
        $build['#games'][] = [
          'alias' => \Drupal::service('path_alias.manager')->getAliasByPath('/node/'.$game->id()),
          'pats_score' => $game->field_patriots_score->value,
          'opp_score' => $game->field_opponent_score->value,
          'opponent' => end($opponent),
          'season' => $game->field_season->value,
          'week' => $game->field_week->entity->label(),
          'css' => $css,
          'highlight' => $muse_id,
        ];
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
