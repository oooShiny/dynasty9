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

class TeamSelectForm extends FormBase {
  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'team_select_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $options = [];
    $nids = \Drupal::entityQuery('node')->accessCheck(TRUE)->condition('type','team')->execute();
    $nodes =  Node::loadMultiple($nids);
    foreach ($nodes as $node) {
      $options[rawurlencode($node->label())] = $node->label();
    }
    $form['team'] = [
      '#type' => 'select2',
      '#default_value' => isset($config['team']) ? $config['team'] : '',
      '#options' => $options,
      '#empty_option' => 'Select a team',
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
    $path = '/search/games?f[0]=opponent:' . $values['team'];
    $url = Url::fromUserInput($path);
    $form_state->setRedirectUrl($url);
  }
}
