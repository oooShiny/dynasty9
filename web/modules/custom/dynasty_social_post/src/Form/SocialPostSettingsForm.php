<?php

namespace Drupal\dynasty_social_post\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure Bluesky Social Post settings.
 */
class SocialPostSettingsForm extends ConfigFormBase {

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

    $form['bluesky_credentials'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Bluesky Credentials'),
      '#description' => $this->t('Enter your Bluesky account credentials. Note: Your email must be verified on Bluesky to post videos.'),
    ];

    $form['bluesky_credentials']['bluesky_identifier'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Bluesky Identifier'),
      '#description' => $this->t('Your Bluesky handle (e.g., user.bsky.social) or email address.'),
      '#default_value' => $config->get('bluesky_identifier'),
      '#required' => TRUE,
    ];

    $form['bluesky_credentials']['bluesky_password'] = [
      '#type' => 'password',
      '#title' => $this->t('Bluesky App Password'),
      '#description' => $this->t('Your Bluesky app password. For security, use an app-specific password rather than your main password. Leave blank to keep existing password.'),
      '#default_value' => '',
    ];

    if ($config->get('bluesky_password')) {
      $form['bluesky_credentials']['password_status'] = [
        '#markup' => '<p><strong>' . $this->t('A password is currently saved.') . '</strong></p>',
      ];
    }

    $form['posting_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Posting Settings'),
    ];

    $form['posting_settings']['enable_auto_post'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable automatic posting'),
      '#description' => $this->t('When enabled, a random highlight will be posted to Bluesky on a scheduled basis via cron.'),
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
      '#markup' => '<p>' . $this->t('Highlights posted: @count', ['@count' => $posted_count]) . '</p>',
    ];

    $form['actions']['reset_posted'] = [
      '#type' => 'submit',
      '#value' => $this->t('Reset posted highlights list'),
      '#submit' => ['::resetPostedHighlights'],
      '#limit_validation_errors' => [],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('dynasty_social_post.settings');

    $config->set('bluesky_identifier', $form_state->getValue('bluesky_identifier'));
    $config->set('enable_auto_post', $form_state->getValue('enable_auto_post'));
    $config->set('post_interval', $form_state->getValue('post_interval'));

    // Only update password if a new one was provided.
    $password = $form_state->getValue('bluesky_password');
    if (!empty($password)) {
      $config->set('bluesky_password', $password);
    }

    $config->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Submit handler to reset the posted highlights list.
   */
  public function resetPostedHighlights(array &$form, FormStateInterface $form_state) {
    \Drupal::state()->set('dynasty_social_post.posted_highlights', []);
    \Drupal::messenger()->addStatus($this->t('Posted highlights list has been reset.'));
  }

}
