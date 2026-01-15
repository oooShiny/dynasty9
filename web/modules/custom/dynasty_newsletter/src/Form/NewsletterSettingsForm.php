<?php

namespace Drupal\dynasty_newsletter\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure newsletter settings.
 */
class NewsletterSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['dynasty_newsletter.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'dynasty_newsletter_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('dynasty_newsletter.settings');

    $form['frequency'] = [
      '#type' => 'select',
      '#title' => $this->t('Newsletter Frequency'),
      '#description' => $this->t('How often should newsletters be automatically generated?'),
      '#options' => [
        'weekly' => $this->t('Weekly'),
        'bi-weekly' => $this->t('Bi-weekly (every 2 weeks)'),
        'monthly' => $this->t('Monthly'),
        'manual' => $this->t('Manual only (no automatic generation)'),
      ],
      '#default_value' => $config->get('frequency') ?? 'weekly',
    ];

    $form['send_day'] = [
      '#type' => 'select',
      '#title' => $this->t('Send Day'),
      '#description' => $this->t('Which day of the week should newsletters be generated? (Used for reference only, actual timing depends on cron schedule)'),
      '#options' => [
        'monday' => $this->t('Monday'),
        'tuesday' => $this->t('Tuesday'),
        'wednesday' => $this->t('Wednesday'),
        'thursday' => $this->t('Thursday'),
        'friday' => $this->t('Friday'),
        'saturday' => $this->t('Saturday'),
        'sunday' => $this->t('Sunday'),
      ],
      '#default_value' => $config->get('send_day') ?? 'thursday',
    ];

    $form['send_time'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Send Time'),
      '#description' => $this->t('What time should newsletters be sent? (Format: HH:MM, e.g., 10:00)'),
      '#default_value' => $config->get('send_time') ?? '10:00',
    ];

    $form['editor_emails'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Editor Email Addresses'),
      '#description' => $this->t('Email addresses to notify when a newsletter draft is ready. One per line.'),
      '#default_value' => implode("\n", $config->get('editor_emails') ?? ['arbrown83@gmail.com']),
    ];

    $form['content_limits'] = [
      '#type' => 'details',
      '#title' => $this->t('Content Limits'),
      '#open' => TRUE,
    ];

    $form['content_limits']['news_items_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('News Items Limit'),
      '#description' => $this->t('Maximum number of recent news items to include.'),
      '#default_value' => $config->get('news_items_limit') ?? 5,
      '#min' => 1,
      '#max' => 20,
    ];

    $form['content_limits']['recent_games_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Recent Games Limit'),
      '#description' => $this->t('Maximum number of recent games to include.'),
      '#default_value' => $config->get('recent_games_limit') ?? 3,
      '#min' => 1,
      '#max' => 10,
    ];

    $form['content_limits']['recent_podcasts_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Recent Podcasts Limit'),
      '#description' => $this->t('Maximum number of recent podcast episodes to include.'),
      '#default_value' => $config->get('recent_podcasts_limit') ?? 3,
      '#min' => 1,
      '#max' => 10,
    ];

    $form['content_limits']['historical_games_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Historical Games Limit'),
      '#description' => $this->t('Maximum number of historical games to include.'),
      '#default_value' => $config->get('historical_games_limit') ?? 5,
      '#min' => 1,
      '#max' => 20,
    ];

    $form['content_limits']['birthdays_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Player Birthdays Limit'),
      '#description' => $this->t('Maximum number of player birthdays to include.'),
      '#default_value' => $config->get('birthdays_limit') ?? 10,
      '#min' => 1,
      '#max' => 50,
    ];

    $form['content_limits']['events_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Historical Events Limit'),
      '#description' => $this->t('Maximum number of historical events to include.'),
      '#default_value' => $config->get('events_limit') ?? 5,
      '#min' => 1,
      '#max' => 20,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $editor_emails_raw = $form_state->getValue('editor_emails');
    $editor_emails = array_filter(array_map('trim', explode("\n", $editor_emails_raw)));

    $this->config('dynasty_newsletter.settings')
      ->set('frequency', $form_state->getValue('frequency'))
      ->set('send_day', $form_state->getValue('send_day'))
      ->set('send_time', $form_state->getValue('send_time'))
      ->set('editor_emails', $editor_emails)
      ->set('news_items_limit', $form_state->getValue('news_items_limit'))
      ->set('recent_games_limit', $form_state->getValue('recent_games_limit'))
      ->set('recent_podcasts_limit', $form_state->getValue('recent_podcasts_limit'))
      ->set('historical_games_limit', $form_state->getValue('historical_games_limit'))
      ->set('birthdays_limit', $form_state->getValue('birthdays_limit'))
      ->set('events_limit', $form_state->getValue('events_limit'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
