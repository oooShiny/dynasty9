<?php
/**
 * @file
 * Contains \Drupal\dynasty_module\Form\PlayerSelectForm.
 */
namespace Drupal\dynasty_module\Plugin\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\taxonomy\Entity\Term;

class PlayTypeSelectForm extends FormBase {
  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'play_type_select_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $options = [];
    $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties([
      'vid' => 'play_type',
    ]);
    foreach ($terms as $term) {
      $options[rawurlencode($term->label())] = $term->label();
    }
    $form['play_types'] = [
      '#type' => 'select2',
      '#default_value' => isset($config['play_types']) ? $config['play_types'] : '',
      '#options' => $options,
      '#empty_option' => 'Select a play type',
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
    $path = '/search/plays?f[0]=play_type:' . $values['play_types'];
    $url = Url::fromUserInput($path);
    $form_state->setRedirectUrl($url);
  }
}
