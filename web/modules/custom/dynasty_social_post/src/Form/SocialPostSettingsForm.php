<?php

namespace Drupal\dynasty_social_post\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\dynasty_social_post\Service\YouTubeService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure Social Post settings for Bluesky and YouTube.
 */
class SocialPostSettingsForm extends ConfigFormBase {

  /**
   * The YouTube service.
   *
   * @var \Drupal\dynasty_social_post\Service\YouTubeService
   */
  protected $youtubeService;

  /**
   * Constructs a SocialPostSettingsForm object.
   *
   * @param \Drupal\dynasty_social_post\Service\YouTubeService $youtube_service
   *   The YouTube service.
   */
  public function __construct(YouTubeService $youtube_service) {
    $this->youtubeService = $youtube_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('dynasty_social_post.youtube')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'dynasty_social_post_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['dynasty_social_post.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('dynasty_social_post.settings');

    // Bluesky Configuration.
    $form['bluesky_credentials'] = [
      '#type' => 'details',
      '#title' => $this->t('Bluesky Configuration'),
      '#description' => $this->t('Enter your Bluesky account credentials. Note: Your email must be verified on Bluesky to post videos.'),
      '#open' => TRUE,
    ];

    $form['bluesky_credentials']['enable_bluesky'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Bluesky posting'),
      '#description' => $this->t('When enabled, highlights will be posted to Bluesky.'),
      '#default_value' => $config->get('enable_bluesky') ?? TRUE,
    ];

    $form['bluesky_credentials']['bluesky_identifier'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Bluesky Identifier'),
      '#description' => $this->t('Your Bluesky handle (e.g., user.bsky.social) or email address.'),
      '#default_value' => $config->get('bluesky_identifier'),
      '#states' => [
        'visible' => [
          ':input[name="enable_bluesky"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['bluesky_credentials']['bluesky_password'] = [
      '#type' => 'password',
      '#title' => $this->t('Bluesky App Password'),
      '#description' => $this->t('Your Bluesky app password. For security, use an app-specific password rather than your main password. Leave blank to keep existing password.'),
      '#default_value' => '',
      '#states' => [
        'visible' => [
          ':input[name="enable_bluesky"]' => ['checked' => TRUE],
        ],
      ],
    ];

    if ($config->get('bluesky_password')) {
      $form['bluesky_credentials']['password_status'] = [
        '#markup' => '<p><strong>' . $this->t('Bluesky password is configured.') . '</strong></p>',
        '#states' => [
          'visible' => [
            ':input[name="enable_bluesky"]' => ['checked' => TRUE],
          ],
        ],
      ];
    }

    // YouTube Configuration.
    $form['youtube_credentials'] = [
      '#type' => 'details',
      '#title' => $this->t('YouTube Configuration'),
      '#description' => $this->t('Configure YouTube API credentials for video uploads. You need OAuth 2.0 credentials from the Google Cloud Console.'),
      '#open' => TRUE,
    ];

    $form['youtube_credentials']['enable_youtube'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable YouTube posting'),
      '#description' => $this->t('When enabled, highlights will be posted to YouTube.'),
      '#default_value' => $config->get('enable_youtube') ?? FALSE,
    ];

    $form['youtube_credentials']['youtube_client_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('YouTube Client ID'),
      '#description' => $this->t('OAuth 2.0 Client ID from Google Cloud Console.'),
      '#default_value' => $config->get('youtube_client_id'),
      '#states' => [
        'visible' => [
          ':input[name="enable_youtube"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['youtube_credentials']['youtube_client_secret'] = [
      '#type' => 'password',
      '#title' => $this->t('YouTube Client Secret'),
      '#description' => $this->t('OAuth 2.0 Client Secret from Google Cloud Console. Leave blank to keep existing.'),
      '#default_value' => '',
      '#states' => [
        'visible' => [
          ':input[name="enable_youtube"]' => ['checked' => TRUE],
        ],
      ],
    ];

    if ($config->get('youtube_client_secret')) {
      $form['youtube_credentials']['youtube_secret_status'] = [
        '#markup' => '<p><strong>' . $this->t('YouTube Client Secret is configured.') . '</strong></p>',
        '#states' => [
          'visible' => [
            ':input[name="enable_youtube"]' => ['checked' => TRUE],
          ],
        ],
      ];
    }

    // YouTube Authorization Status.
    $form['youtube_credentials']['youtube_auth_status'] = [
      '#type' => 'container',
      '#states' => [
        'visible' => [
          ':input[name="enable_youtube"]' => ['checked' => TRUE],
        ],
      ],
    ];

    if ($this->youtubeService->isConfigured()) {
      $channel_info = $this->youtubeService->getChannelInfo();
      if ($channel_info) {
        $form['youtube_credentials']['youtube_auth_status']['status'] = [
          '#markup' => '<p class="color-success"><strong>' . $this->t('Connected to YouTube channel: @channel', [
            '@channel' => $channel_info['title'],
          ]) . '</strong></p>',
        ];

        $disconnect_url = Url::fromRoute('dynasty_social_post.youtube_disconnect');
        $form['youtube_credentials']['youtube_auth_status']['disconnect'] = [
          '#type' => 'link',
          '#title' => $this->t('Disconnect YouTube Account'),
          '#url' => $disconnect_url,
          '#attributes' => [
            'class' => ['button', 'button--danger'],
          ],
        ];
      }
      else {
        $form['youtube_credentials']['youtube_auth_status']['status'] = [
          '#markup' => '<p class="color-warning"><strong>' . $this->t('YouTube tokens exist but could not verify channel. Try re-authorizing.') . '</strong></p>',
        ];
      }
    }
    else {
      $has_credentials = $config->get('youtube_client_id') && $config->get('youtube_client_secret');

      if ($has_credentials) {
        $auth_url = $this->youtubeService->getAuthorizationUrl();
        $form['youtube_credentials']['youtube_auth_status']['status'] = [
          '#markup' => '<p>' . $this->t('YouTube is not authorized. Click the button below to connect your YouTube account.') . '</p>',
        ];
        $form['youtube_credentials']['youtube_auth_status']['authorize'] = [
          '#type' => 'link',
          '#title' => $this->t('Authorize YouTube Account'),
          '#url' => Url::fromUri($auth_url),
          '#attributes' => [
            'class' => ['button', 'button--primary'],
          ],
        ];
      }
      else {
        $form['youtube_credentials']['youtube_auth_status']['status'] = [
          '#markup' => '<p>' . $this->t('Enter your Client ID and Client Secret above, save the form, then authorize.') . '</p>',
        ];
      }
    }

    // YouTube posted highlights count.
    $youtube_posted_count = count(\Drupal::state()->get('dynasty_social_post.youtube_posted_highlights', []));
    $form['youtube_credentials']['youtube_posted_count'] = [
      '#markup' => '<p>' . $this->t('Highlights posted to YouTube: @count', ['@count' => $youtube_posted_count]) . '</p>',
      '#states' => [
        'visible' => [
          ':input[name="enable_youtube"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Posting Settings.
    $form['posting_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Posting Settings'),
      '#open' => TRUE,
    ];

    $form['posting_settings']['enable_auto_post'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable automatic posting'),
      '#description' => $this->t('When enabled, a random highlight will be posted to enabled platforms on a scheduled basis via cron.'),
      '#default_value' => $config->get('enable_auto_post'),
    ];

    $form['posting_settings']['post_interval'] = [
      '#type' => 'select',
      '#title' => $this->t('Posting interval'),
      '#description' => $this->t('How often to post highlights automatically.'),
      '#options' => [
        3600 => $this->t('Every hour'),
        21600 => $this->t('Every 6 hours'),
        43200 => $this->t('Every 12 hours'),
        86400 => $this->t('Once per day'),
        172800 => $this->t('Every 2 days'),
        604800 => $this->t('Once per week'),
      ],
      '#default_value' => $config->get('post_interval') ?: 86400,
      '#states' => [
        'visible' => [
          ':input[name="enable_auto_post"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $last_post = \Drupal::state()->get('dynasty_social_post.last_post_time', 0);
    if ($last_post > 0) {
      $form['posting_settings']['last_post_info'] = [
        '#markup' => '<p>' . $this->t('Last post: @time', [
          '@time' => \Drupal::service('date.formatter')->format($last_post, 'long'),
        ]) . '</p>',
      ];
    }

    $posted_count = count(\Drupal::state()->get('dynasty_social_post.posted_highlights', []));
    $form['posting_settings']['posted_count'] = [
      '#markup' => '<p>' . $this->t('Highlights posted to Bluesky: @count', ['@count' => $posted_count]) . '</p>',
    ];

    $form['actions']['reset_posted'] = [
      '#type' => 'submit',
      '#value' => $this->t('Reset Bluesky posted highlights list'),
      '#submit' => ['::resetPostedHighlights'],
      '#limit_validation_errors' => [],
    ];

    $form['actions']['reset_youtube_posted'] = [
      '#type' => 'submit',
      '#value' => $this->t('Reset YouTube posted highlights list'),
      '#submit' => ['::resetYouTubePostedHighlights'],
      '#limit_validation_errors' => [],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('dynasty_social_post.settings');

    // Bluesky settings.
    $config->set('enable_bluesky', $form_state->getValue('enable_bluesky'));
    $config->set('bluesky_identifier', $form_state->getValue('bluesky_identifier'));

    $password = $form_state->getValue('bluesky_password');
    if (!empty($password)) {
      $config->set('bluesky_password', $password);
    }

    // YouTube settings.
    $config->set('enable_youtube', $form_state->getValue('enable_youtube'));
    $config->set('youtube_client_id', $form_state->getValue('youtube_client_id'));

    $youtube_secret = $form_state->getValue('youtube_client_secret');
    if (!empty($youtube_secret)) {
      $config->set('youtube_client_secret', $youtube_secret);
    }

    // Posting settings.
    $config->set('enable_auto_post', $form_state->getValue('enable_auto_post'));
    $config->set('post_interval', $form_state->getValue('post_interval'));

    $config->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Submit handler to reset the Bluesky posted highlights list.
   */
  public function resetPostedHighlights(array &$form, FormStateInterface $form_state) {
    \Drupal::state()->set('dynasty_social_post.posted_highlights', []);
    \Drupal::messenger()->addStatus($this->t('Bluesky posted highlights list has been reset.'));
  }

  /**
   * Submit handler to reset the YouTube posted highlights list.
   */
  public function resetYouTubePostedHighlights(array &$form, FormStateInterface $form_state) {
    \Drupal::state()->set('dynasty_social_post.youtube_posted_highlights', []);
    \Drupal::messenger()->addStatus($this->t('YouTube posted highlights list has been reset.'));
  }

}
