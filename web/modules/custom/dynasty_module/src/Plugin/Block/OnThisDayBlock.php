<?php

namespace Drupal\dynasty_module\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
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
    $events = $this->getEvents($today);

    return [
      '#theme' => 'on_this_day_block',
      '#attributes' => ['class' => 'mx-auto w-fit'],
      '#games' => $games,
      '#birthdays' => [],
      '#events' => $events,
      '#limit' => $this->configuration['on_this_day_block_games'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'on_this_day_block_games' => 0,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $form = parent::blockForm($form, $form_state);

    $form['on_this_day_block_games'] = [
      '#type' => 'number',
      '#title' => $this->t('Number of Games'),
      '#description' => $this->t('Limit the number of games (0 is no limit).'),
      '#default_value' => $this->configuration['on_this_day_block_games'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $this->configuration['on_this_day_block_games'] = $values['on_this_day_block_games'];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    // Cache for 6 hours - date won't change that often
    // and this is an extremely expensive operation
    return 21600;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    return ['node_list:game', 'node_list:highlight'];
  }

  function getGames($today) {
    $todays_games = ['with_video' => [], 'no_video' => []];

    // OPTIMIZATION: Only load games matching today's date instead of ALL games
    // Extract month and day for direct DB query
    [$month, $day] = explode('-', $today);

    $game_nids = \Drupal::database()->select('node__field_date', 'fd')
      ->fields('fd', ['entity_id'])
      ->condition('fd.bundle', 'game')
      ->where("MONTH(fd.field_date_value) = :month", [':month' => $month])
      ->where("DAY(fd.field_date_value) = :day", [':day' => $day])
      ->execute()
      ->fetchCol();

    if (empty($game_nids)) {
      return $todays_games;
    }

    $games = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadMultiple($game_nids);


    foreach ($games as $game) {
      // Games are already filtered by date in the query, no need to check again
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
        $todays_games['with_video'][] = [
          'alias' => \Drupal::service('path_alias.manager')->getAliasByPath('/node/'.$game->id()),
          'pats_score' => $game->field_patriots_score->value,
          'opp_score' => $game->field_opponent_score->value,
          'opponent' => end($opponent),
          'season' => $game->field_season->value,
          'week' => $game->field_week->entity->label(),
          'css' => $css,
          'highlight' => $muse_id,
          'result' => $game->field_result->value,
        ];
      }
      else {
        $todays_games['no_video'][] = [
          'alias' => \Drupal::service('path_alias.manager')->getAliasByPath('/node/'.$game->id()),
          'pats_score' => $game->field_patriots_score->value,
          'opp_score' => $game->field_opponent_score->value,
          'opponent' => end($opponent),
          'season' => $game->field_season->value,
          'week' => $game->field_week->entity->label(),
          'css' => $css,
          'result' => $game->field_result->value,
        ];
      }
    }
    return $todays_games;
  }

  function getEvents($today) {
    // OPTIMIZATION: Query events by date instead of loading all events
    [$month, $day] = explode('-', $today);

    $event_nids = \Drupal::database()->select('node__field_event_date', 'fed')
      ->fields('fed', ['entity_id'])
      ->condition('fed.bundle', 'event')
      ->where("MONTH(fed.field_event_date_value) = :month", [':month' => $month])
      ->where("DAY(fed.field_event_date_value) = :day", [':day' => $day])
      ->execute()
      ->fetchCol();

    if (empty($event_nids)) {
      return [];
    }

    $events = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadMultiple($event_nids);

    return $events;
  }
}

