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
        'class' => ['h-12 w-64']
      ],
    ];

    $form['submit'] = array(
      '#type' => 'submit',
      '#value' => $this->t('Go'),
      '#attributes' => [
        'class' => ['h-12 px-8 text-white bg-red-pats border border-transparent hover:bg-red-800']
      ],
    );
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    // The select's option keys are rawurlencode()'d term labels (see
    // ::buildForm() above); decode back to the plain label and let Url
    // handle query-string encoding, so it matches what the Highlight
    // Search page's JS actually filters on (play_type.label), rather than
    // the old f[0]=play_type:<value> Facets/Views-style param it never
    // understood.
    $url = Url::fromUserInput('/search/highlights', [
      'query' => ['play_type' => rawurldecode($values['play_types'])],
    ]);
    $form_state->setRedirectUrl($url);
  }
}
