<?php

namespace Drupal\dynasty_voicemail\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure Dynasty Voicemail settings.
 */
class VoicemailSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'dynasty_voicemail_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['dynasty_voicemail.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('dynasty_voicemail.settings');

    $form['notification_emails'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Notification Email Addresses'),
      '#description' => $this->t('Enter email addresses to receive voicemail notifications. One per line or comma-separated.'),
      '#default_value' => $config->get('notification_emails'),
      '#required' => TRUE,
    ];

    $form['max_duration'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum Recording Duration'),
      '#description' => $this->t('Maximum recording time in seconds.'),
      '#default_value' => $config->get('max_duration') ?? 120,
      '#min' => 10,
      '#max' => 300,
      '#required' => TRUE,
    ];

    $form['rate_limit_per_hour'] = [
      '#type' => 'number',
      '#title' => $this->t('Rate Limit Per Hour'),
      '#description' => $this->t('Maximum number of submissions per IP address per hour. Helps prevent spam.'),
      '#default_value' => $config->get('rate_limit_per_hour') ?? 5,
      '#min' => 1,
      '#max' => 100,
      '#required' => TRUE,
    ];

    $form['honeypot_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Honeypot Field'),
      '#description' => $this->t('Add a hidden field to catch automated spam submissions.'),
      '#default_value' => $config->get('honeypot_enabled') ?? TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    // Validate email addresses.
    $emails = $form_state->getValue('notification_emails');
    $emails = preg_split('/[\s,]+/', $emails, -1, PREG_SPLIT_NO_EMPTY);

    foreach ($emails as $email) {
      if (!\Drupal::service('email.validator')->isValid(trim($email))) {
        $form_state->setErrorByName('notification_emails', $this->t('Invalid email address: @email', ['@email' => $email]));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('dynasty_voicemail.settings')
      ->set('notification_emails', $form_state->getValue('notification_emails'))
      ->set('max_duration', $form_state->getValue('max_duration'))
      ->set('rate_limit_per_hour', $form_state->getValue('rate_limit_per_hour'))
      ->set('honeypot_enabled', $form_state->getValue('honeypot_enabled'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
