<?php

namespace Drupal\dynasty_plays\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Class PlaySettingsForm.
 *
 * @ingroup dynasty_plays
 */
class PlaySettingsForm extends FormBase {

  /**
   * Returns a unique string identifying the form.
   *
   * @return string
   *   The unique string identifying the form.
   */
  public function getFormId() {
    return 'play_settings';
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
   * Defines the settings form for Play entities.
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
      '#markup' => $this->t('Configure settings for Play entities. Use the tabs above to manage fields, form display, and view display.'),
    ];

    $form['links'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Quick Links'),
    ];

    $form['links']['list'] = [
      '#type' => 'item',
      '#markup' => $this->t('<ul>
        <li><a href="@fields">Manage fields</a> - Add, edit, or remove fields for Play entities</li>
        <li><a href="@form">Manage form display</a> - Configure how fields appear on the add/edit forms</li>
        <li><a href="@display">Manage display</a> - Configure how fields appear when viewing plays</li>
        <li><a href="@collection">View all plays</a> - Browse all play entities</li>
        <li><a href="@add">Add a play</a> - Create a new play entity</li>
      </ul>', [
        '@fields' => '/admin/structure/play/settings/fields',
        '@form' => '/admin/structure/play/settings/form-display',
        '@display' => '/admin/structure/play/settings/display',
        '@collection' => '/admin/content/play',
        '@add' => '/admin/content/play/add',
      ]),
    ];

    return $form;
  }

}
