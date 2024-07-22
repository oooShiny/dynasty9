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
    $path = '/search/games?search=&f[0]=game_season:' . $values['seasons'];
    $url = Url::fromUserInput($path);
    $form_state->setRedirectUrl($url);
  }
}
