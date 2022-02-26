<?php

namespace Drupal\patreon\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\patreon\PatreonServiceInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form to store API Client and Secret.
 *
 * @package Drupal\patreon\Form
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'patreon.settings',
    ];
  }

  /**
   * The Patreon API service.
   *
   * @var \Drupal\patreon\PatreonServiceInterface
   */
  protected PatreonServiceInterface $service;

  /**
   * SettingsForm constructor.
   *
   * @param \Drupal\patreon\PatreonServiceInterface $service
   *   A Patreon API service.
   */
  public function __construct(PatreonServiceInterface $service) {
    $this->service = $service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('patreon.api')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('patreon.settings');
    $form['oauth'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('OAuth Settings'),
      '#description' => $this->t('To enable OAuth based access for patreon, you must <a href="@url">register this site</a> with Patreon and add the provided keys here. When asked, you should specify you are using Version 2 of the API.',
        [
          '@url' => PATREON_REGISTER_OAUTH_URL,
        ],
      ),
    ];
    $form['oauth']['endpoint'] = [
      '#markup' => $this->t('<p>When registering with Patreon, you must add @url as your application endpoint.</p>', [
        '@url' => $this->service->getCallback()->toString(),
      ]),
    ];
    $form['oauth']['patreon_client_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Patreon Client ID'),
      '#maxlength' => 64,
      '#size' => 64,
      '#default_value' => $config->get('patreon_client_id'),
      '#required' => TRUE,
    ];
    $form['oauth']['patreon_client_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Patreon Client Secret'),
      '#maxlength' => 64,
      '#size' => 64,
      '#default_value' => $config->get('patreon_client_secret'),
      '#required' => TRUE,
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);

    $this->config('patreon.settings')
      ->set('patreon_client_id', $form_state->getValue('patreon_client_id'))
      ->set('patreon_client_secret', $form_state->getValue('patreon_client_secret'))
      ->save();

    $redirect = $this->service->authoriseAccount($form_state->getValue('patreon_client_id'), [
      'identity',
      'identity[email]',
      'identity.memberships',
      'campaigns',
      'campaigns.members',
      'campaigns.members[email]',
      'campaigns.members.address',
      'campaigns.posts',
    ]);
    $form_state->setResponse($redirect);
  }

}
