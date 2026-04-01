<?php

namespace Drupal\dynasty_newsletter\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure newsletter settings.
 */
class NewsletterSettingsForm extends ConfigFormBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->database = $container->get('database');
    return $instance;
  }

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

    $form['ai_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('AI / LLM Integration'),
      '#open' => (bool) $config->get('llm_enabled'),
      '#description' => $this->t('Configure a local LLM to curate news items and write summaries. Run <code>ddev drush dynasty-newsletter:generate --remote</code> to generate a draft on the live site using your local LLM.'),
    ];

    $form['ai_settings']['remote_site_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Remote site URL'),
      '#description' => $this->t('Production site URL used by the <code>--remote</code> Drush flag. Credentials are read from <code>NEWSLETTER_REMOTE_USER</code> and <code>NEWSLETTER_REMOTE_PASS</code> environment variables — never stored in config.'),
      '#default_value' => $config->get('remote_site_url') ?? '',
      '#placeholder' => 'https://patriotsdynasty.info',
    ];

    $form['ai_settings']['llm_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable LLM news curation'),
      '#description' => $this->t('Use a local LLM to select the most relevant news items and write short summaries. Runs when generating newsletters via Drush. Requires Ollama or LMStudio running on your machine.'),
      '#default_value' => $config->get('llm_enabled') ?? FALSE,
    ];

    $form['ai_settings']['llm_api_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('LLM API Base URL'),
      '#description' => $this->t('Base URL of an OpenAI-compatible API. From DDEV, use <code>http://host.docker.internal:11434</code> for Ollama or <code>http://host.docker.internal:1234</code> for LMStudio.'),
      '#default_value' => $config->get('llm_api_url') ?? 'http://host.docker.internal:11434',
      '#placeholder' => 'http://host.docker.internal:11434',
      '#states' => [
        'visible' => [':input[name="llm_enabled"]' => ['checked' => TRUE]],
      ],
    ];

    $form['ai_settings']['llm_model'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Model name'),
      '#description' => $this->t('The model to use, e.g. <code>llama3.2</code>, <code>mistral</code>, or whatever is loaded in your LLM runtime.'),
      '#default_value' => $config->get('llm_model') ?? 'llama3.2',
      '#placeholder' => 'llama3.2',
      '#states' => [
        'visible' => [':input[name="llm_enabled"]' => ['checked' => TRUE]],
      ],
    ];

    $form['ai_settings']['llm_news_pool_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Candidate pool size'),
      '#description' => $this->t('Number of recent news items to fetch and send to the LLM before it selects the best ones. Should be larger than the News Items Limit above.'),
      '#default_value' => $config->get('llm_news_pool_size') ?? 20,
      '#min' => 5,
      '#max' => 50,
      '#states' => [
        'visible' => [':input[name="llm_enabled"]' => ['checked' => TRUE]],
      ],
    ];

    // Build a list of aggregator feeds so the admin can tag podcast feeds.
    $feed_options = [];
    try {
      $feed_options = $this->database->select('aggregator_feed', 'af')
        ->fields('af', ['fid', 'title'])
        ->orderBy('af.title')
        ->execute()
        ->fetchAllKeyed();
    }
    catch (\Exception $e) {
      // Aggregator table may not exist.
    }

    $form['podcast_feeds'] = [
      '#type' => 'details',
      '#title' => $this->t('Podcast Feeds'),
      '#open' => TRUE,
    ];
    $form['podcast_feeds']['podcast_feed_ids'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Podcast RSS Feeds'),
      '#description' => $this->t('Mark which aggregator feeds contain podcast episodes. These will appear in the "External Podcast Episodes" section instead of the News Items section.'),
      '#options' => $feed_options,
      '#default_value' => $config->get('podcast_feed_ids') ?? [],
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
      ->set('podcast_feed_ids', array_values(array_filter($form_state->getValue('podcast_feed_ids'))))
      ->set('remote_site_url', rtrim(trim($form_state->getValue('remote_site_url')), '/'))
      ->set('llm_enabled', (bool) $form_state->getValue('llm_enabled'))
      ->set('llm_api_url', trim($form_state->getValue('llm_api_url')))
      ->set('llm_model', trim($form_state->getValue('llm_model')))
      ->set('llm_news_pool_size', (int) $form_state->getValue('llm_news_pool_size'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
