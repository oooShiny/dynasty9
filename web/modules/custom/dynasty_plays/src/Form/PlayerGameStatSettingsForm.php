<?php

namespace Drupal\dynasty_plays\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Class PlayerGameStatSettingsForm.
 *
 * @ingroup dynasty_plays
 */
class PlayerGameStatSettingsForm extends FormBase {

  /**
   * Returns a unique string identifying the form.
   *
   * @return string
   *   The unique string identifying the form.
   */
  public function getFormId() {
    return 'player_game_stat_settings';
  }

  /**
   * Form submission handler.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Empty implementation of the abstract submit method.
  }

  /**
   * Defines the settings form for Player Game Stat entities.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return array
   *   Form definition array.
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['help'] = [
      '#type' => 'item',
      '#markup' => $this->t('Configure settings for Player Game Stat entities. Use the tabs above to manage fields, form display, and view display.'),
    ];

    $form['links'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Quick Links'),
    ];

    $form['links']['list'] = [
      '#type' => 'item',
      '#markup' => $this->t('<ul>
        <li><a href="@fields">Manage fields</a> - Add, edit, or remove fields for Player Game Stat entities</li>
        <li><a href="@form">Manage form display</a> - Configure how fields appear on the add/edit forms</li>
        <li><a href="@display">Manage display</a> - Configure how fields appear when viewing player game stats</li>
        <li><a href="@collection">View all player game stats</a> - Browse all player game stat entities</li>
        <li><a href="@add">Add a player game stat</a> - Create a new player game stat entity</li>
      </ul>', [
        '@fields' => '/admin/structure/player-game-stat/settings/fields',
        '@form' => '/admin/structure/player-game-stat/settings/form-display',
        '@display' => '/admin/structure/player-game-stat/settings/display',
        '@collection' => '/admin/content/player-game-stat',
        '@add' => '/admin/content/player-game-stat/add',
      ]),
    ];

    return $form;
  }

}
