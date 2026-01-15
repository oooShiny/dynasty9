<?php

namespace Drupal\dynasty_newsletter\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure RSS feed sources for newsletter.
 */
class NewsletterRSSSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['dynasty_newsletter.rss_settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'dynasty_newsletter_rss_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('dynasty_newsletter.rss_settings');

    $form['help'] = [
      '#markup' => '<p>' . $this->t('Configure RSS feed sources for the newsletter. These feeds should be added to the Aggregator module at <a href="/admin/config/services/aggregator">Aggregator settings</a>.') . '</p>',
    ];

    $form['rss_feeds'] = [
      '#type' => 'textarea',
      '#title' => $this->t('RSS Feed URLs'),
      '#description' => $this->t('Enter RSS feed URLs, one per line. Example:<br>ESPN Patriots: https://www.espn.com/espn/rss/nfl/team/_/name/ne/new-england-patriots<br>NFL.com: https://www.nfl.com/feeds/rss/team/new-england-patriots'),
      '#default_value' => implode("\n", $config->get('rss_feeds') ?? []),
      '#rows' => 10,
    ];

    $form['update_interval'] = [
      '#type' => 'select',
      '#title' => $this->t('Update Interval'),
      '#description' => $this->t('How often should RSS feeds be updated?'),
      '#options' => [
        '3600' => $this->t('Every hour'),
        '10800' => $this->t('Every 3 hours'),
        '21600' => $this->t('Every 6 hours'),
        '43200' => $this->t('Every 12 hours'),
        '86400' => $this->t('Once a day'),
      ],
      '#default_value' => $config->get('update_interval') ?? '10800',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $rss_feeds_raw = $form_state->getValue('rss_feeds');
    $rss_feeds = array_filter(array_map('trim', explode("\n", $rss_feeds_raw)));

    $this->config('dynasty_newsletter.rss_settings')
      ->set('rss_feeds', $rss_feeds)
      ->set('update_interval', $form_state->getValue('update_interval'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
