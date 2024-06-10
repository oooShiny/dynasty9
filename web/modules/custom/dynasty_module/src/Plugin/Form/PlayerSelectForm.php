<?php
/**
 * @file
 * Contains \Drupal\dynasty_module\Form\PlayerSelectForm.
 */
namespace Drupal\dynasty_module\Plugin\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use \Drupal\node\Entity\Node;

class PlayerSelectForm extends FormBase {
  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'player_select_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $options = [];
    $nids = \Drupal::entityQuery('node')->accessCheck(TRUE)->condition('type','player')->execute();
    $nodes =  Node::loadMultiple($nids);
    foreach ($nodes as $node) {
      $options[$node->id()] = $node->label();
    }
    $form['players'] = [
      '#type' => 'select2',
      '#default_value' => isset($config['players']) ? $config['players'] : '',
      '#options' => $options,
      '#empty_option' => 'Select a player',
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
    $path = '/node/' . $values['players'];
    $url = Url::fromUserInput($path);
    $form_state->setRedirectUrl($url);
  }
}
