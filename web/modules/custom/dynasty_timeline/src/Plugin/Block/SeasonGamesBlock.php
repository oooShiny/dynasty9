<?php

namespace Drupal\dynasty_timeline\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\Node;

/**
 * Provides a Block that displays all games from the selected season.
 *
 * @Block(
 *   id = "season_game_block",
 *   admin_label = @Translation("Season Game List Block"),
 *   category = @Translation("Dynasty"),
 * )
 */
class SeasonGamesBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    $config = $this->getConfiguration();
    // Get all game win/loss data.
    $query = \Drupal::entityQuery('node')
      ->accessCheck(FALSE)
      ->condition('type', 'game')
      ->condition('status', 1)
      ->condition('field_season', $config['seasons'])
      ->sort('field_date', 'ASC');
    $game_nids = $query->execute();

    return [
      '#theme' => 'season_games_list',
      '#games' => $game_nids,
    ];  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $form = parent::blockForm($form, $form_state);
    $config = $this->getConfiguration();

    $form['seasons'] = [
      '#type' => 'number',
      '#title' => $this->t('Select season'),
      '#description' => $this->t('This will filter games list by the selected season.'),
      '#default_value' => $config['seasons'] ?? '',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    parent::blockSubmit($form, $form_state);
    $values = $form_state->getValues();
    $this->configuration['seasons'] = $values['seasons'];
  }

}
