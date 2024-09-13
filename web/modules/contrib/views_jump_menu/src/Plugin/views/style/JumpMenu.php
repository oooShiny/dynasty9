<?php

namespace Drupal\views_jump_menu\Plugin\views\style;

use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Plugin\views\style\StylePluginBase;

/**
 * Class JumpMenu.
 *
 * @ViewsStyle(
 *   id = "jump_menu",
 *   title = @Translation("Jump Menu"),
 *   help = @Translation("Displays rows as clickable items in a select list."),
 *   theme = "views_jump_menu",
 *   display_types = {"normal"}
 * )
 *
 * @package Drupal\views_jump_menu\Plugin\views\style
 */
class JumpMenu extends StylePluginBase {

  /**
   * {@inheritdoc}
   */
  protected $usesRowPlugin = FALSE;

  /**
   * {@inheritdoc}
   */
  protected $usesRowClass = FALSE;

  /**
   * {@inheritdoc}
   */
  protected $usesFields = TRUE;

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['wrapper_class'] = ['default' => ''];
    $options['class'] = ['default' => ''];
    $options['label_field'] = ['default' => ''];
    $options['url_field'] = ['default' => ''];
    $options['new_window'] = ['default' => FALSE];
    $options['select_text'] = ['default' => '-- Select --'];
    $options['select_label'] = ['default' => ''];
    return $options;
  }

  /**
   * Generate options array for the select inputs with available field labels.
   *
   * @return array
   *   Options array for the select.
   */
  public function getFieldOptions() {
    $labels = $this->displayHandler->getFieldLabels(TRUE);
    return array_merge(['' => '-- Select --'], $labels);
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);

    $form['wrapper_class'] = [
      '#title' => $this->t('Wrapper class'),
      '#description' => $this->t('The class to provide on the wrapper, outside the select element.'),
      '#type' => 'textfield',
      '#size' => '30',
      '#default_value' => $this->options['wrapper_class'],
    ];

    $form['class'] = [
      '#title' => $this->t('Select class'),
      '#description' => $this->t('The class to provide on the select element itself.'),
      '#type' => 'textfield',
      '#size' => '30',
      '#default_value' => $this->options['class'],
    ];

    $form['label_field'] = [
      '#title' => $this->t('Label field'),
      '#description' => $this->t('The field to use as the text label of each select option.'),
      '#type' => 'select',
      '#options' => $this->getFieldOptions(),
      '#default_value' => $this->options['label_field'],
    ];

    $form['url_field'] = [
      '#title' => $this->t('URL field'),
      '#description' => $this->t('The field to use as the destination URL of each select option.'),
      '#type' => 'select',
      '#options' => $this->getFieldOptions(),
      '#default_value' => $this->options['url_field'],
    ];

    $form['new_window'] = [
      '#title' => $this->t('Open link in new window'),
      '#type' => 'checkbox',
      '#default_value' => $this->options['new_window'],
    ];

    $form['select_text'] = [
      '#title' => $this->t('Select text'),
      '#description' => $this->t('The text to display as the pre-selected option in the jump menu'),
      '#type' => 'textfield',
      '#default_value' => $this->options['select_text'],
    ];

    $form['select_label'] = [
      '#title' => $this->t('Select label'),
      '#description' => $this->t('The description of the jump menu for screen-readers/accessibility.'),
      '#type' => 'textfield',
      '#default_value' => $this->options['select_label'],
    ];
  }

}
