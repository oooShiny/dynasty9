<?php
/**
 * @file
 * Contains \Drupal\dynasty_module\Form\SeasonSelectForm.
 */
namespace Drupal\dynasty_module\Plugin\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

class SeasonSelectForm extends FormBase {
  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'season_select_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $options = [];
    for ($i = 2000; $i < 2021; $i++) {
      $options[$i] = $i;
    }
    $form['seasons'] = [
      '#type' => 'select2',
      '#default_value' => isset($config['seasons']) ? $config['seasons'] : '',
      '#options' => $options,
      '#empty_option' => 'Select a season',
      '#wrapper_attributes' => [
        'class' => ['tw-h-12 tw-w-64']
      ],
    ];

    $form['submit'] = array(
      '#type' => 'submit',
      '#value' => $this->t('Go'),
      '#attributes' => [
        'class' => ['tw-h-12 tw-px-8 tw-text-white tw-bg-red-pats tw-border tw-border-transparent hover:tw-bg-red-800']
      ],
    );
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $path = '/games/' . $values['seasons'];
    $url = Url::fromUserInput($path);
    $form_state->setRedirectUrl($url);
  }
}
