<?php

namespace Drupal\dynasty_module\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
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
    $config = $this->getConfiguration();
    // Get all game win/loss data.
    $query = \Drupal::entityQuery('node')
      ->condition('type', 'game')
      ->condition('field_season', 1999, '>')
      ->condition('status', 1)
      ->accessCheck(TRUE);
    if (isset($config['brady']) && $config['brady'] == 1) {
      $query->condition('field_brady_played', TRUE);
    }
    $game_nids = $query->execute();

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
  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $form = parent::blockForm($form, $form_state);
    $config = $this->getConfiguration();

    $form['brady'] = [
      '#type' => 'select',
      '#title' => $this->t('Patriots or Brady Records?'),
      '#description' => $this->t('Select if the records should be filtered by games Brady played.'),
      '#default_value' => $config['brady'] ?? '',
      '#options' => [
        0 => 'All games',
        1 => 'Only Brady games',
      ],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    parent::blockSubmit($form, $form_state);
    $values = $form_state->getValues();
    $this->configuration['brady'] = $values['brady'];
  }

}
