<?php

namespace Drupal\dynasty_module\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\node\Entity\Node;

/**
 * Provides a Block that displays games/events that happened on the current
 * date.
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
    $today = date('m-d');

    // Get all games and see if the day/month matches up to today.
    $games = $this->getGames($today);

    return [
      '#theme' => 'on_this_day_block',
      '#games' => $games,
      '#birthdays' => [],
      '#events' => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    // Don't cache this block, otherwise it shows the wrong date.
    return 0;
  }

  function getGames($today) {
    $todays_games = [];
    $game_nids = \Drupal::entityQuery('node')
      ->condition('type', 'game')
      ->accessCheck(TRUE)
      ->execute();

    $games = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadMultiple(array_keys($game_nids));


    foreach ($games as $game) {
      $game_date = '';
      if (is_object($game) && $game->hasField('field_date') && !is_null($game->field_date->value)) {
        $game_date = substr($game->field_date->value, 5);
      }
      if ($game_date !== '' && $game_date == $today) {
        // Find highlights for this game.
        $highlight_pog = \Drupal::entityQuery('node')
          ->condition('type', 'highlight')
          ->condition('field_play_of_the_game', 1)
          ->condition('field_game', $game->id())
          ->accessCheck(TRUE)
          ->execute();

        if (!empty($highlight_pog)) {
          $highlight = \Drupal::entityTypeManager()
            ->getStorage('node')
            ->load(end($highlight_pog));
          $muse_id = $highlight->field_muse_video_id->value;
        }
        else {
          $highlight_nid = \Drupal::entityQuery('node')
            ->condition('type', 'highlight')
            ->condition('field_game', $game->id())
            ->accessCheck(TRUE)
            ->execute();
          if (!empty($highlight_nid)) {
            $highlight = \Drupal::entityTypeManager()
              ->getStorage('node')
              ->load($highlight_nid[array_rand($highlight_nid)]);
            $muse_id = $highlight->field_muse_video_id->value;
          }
          else {
            $muse_id = '';
          }
        }


        $opponent = explode(' ', $game->field_opponent->entity->label());
        $css = strtolower(implode('-', $opponent));
        if ($muse_id) {
          $todays_games['#games_highlights'][] = [
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
        else {
          $todays_games['#games'][] = [
            'alias' => \Drupal::service('path_alias.manager')->getAliasByPath('/node/'.$game->id()),
            'pats_score' => $game->field_patriots_score->value,
            'opp_score' => $game->field_opponent_score->value,
            'opponent' => end($opponent),
            'season' => $game->field_season->value,
            'week' => $game->field_week->entity->label(),
            'css' => $css,
          ];
        }
      }
    }

    return $todays_games;
  }
}

