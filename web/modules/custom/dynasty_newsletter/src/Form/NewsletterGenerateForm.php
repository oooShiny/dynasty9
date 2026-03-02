<?php

namespace Drupal\dynasty_newsletter\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\node\Entity\Node;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form to manually generate a newsletter.
 */
class NewsletterGenerateForm extends FormBase {

  /**
   * The newsletter content builder service.
   *
   * @var \Drupal\dynasty_newsletter\Service\NewsletterContentBuilder
   */
  protected $contentBuilder;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->contentBuilder = $container->get('dynasty_newsletter.content_builder');
    $instance->database = $container->get('database');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'dynasty_newsletter_generate_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['help'] = [
      '#markup' => '<p>' . $this->t('Manually generate a newsletter draft. This will create a new unpublished Simplenews issue with pre-populated content from recent news, games, podcasts, and historical data.') . '</p>',
    ];

    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Newsletter Title'),
      '#default_value' => 'Patriots Dynasty Weekly - ' . date('F j, Y'),
      '#required' => TRUE,
    ];

    $newsletter_config = \Drupal::config('dynasty_newsletter.settings');
    $podcast_feed_ids = $newsletter_config->get('podcast_feed_ids') ?? [];

    // --- News Items (excluding podcast feeds, grouped by date) ---
    $news_by_date = [];
    try {
      $timestamp = strtotime('-14 days');
      $news_query = $this->database->select('aggregator_item', 'ai')
        ->fields('ai', ['iid', 'title', 'link', 'timestamp', 'fid'])
        ->condition('ai.timestamp', $timestamp, '>')
        ->orderBy('ai.timestamp', 'DESC');

      if (!empty($podcast_feed_ids)) {
        $news_query->condition('ai.fid', $podcast_feed_ids, 'NOT IN');
      }

      $news_results = $news_query->execute()->fetchAll();

      // Batch-load feed names in a single query.
      $fids = array_unique(array_column($news_results, 'fid'));
      $feed_names = [];
      if (!empty($fids)) {
        $feed_names = $this->database->select('aggregator_feed', 'af')
          ->fields('af', ['fid', 'title'])
          ->condition('af.fid', $fids, 'IN')
          ->execute()
          ->fetchAllKeyed();
      }

      foreach ($news_results as $item) {
        $date_key = date('Ymd', $item->timestamp);
        $feed_name = $feed_names[$item->fid] ?? 'Unknown';
        if (!isset($news_by_date[$date_key])) {
          $news_by_date[$date_key] = [
            'label' => date('l, F j, Y', $item->timestamp),
            'items' => [],
          ];
        }
        $news_by_date[$date_key]['items'][$item->iid] = Markup::create(
          '<a href="' . htmlspecialchars($item->link, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">' . htmlspecialchars($item->title, ENT_QUOTES, 'UTF-8') . '</a>'
          . ' — ' . htmlspecialchars($feed_name, ENT_QUOTES, 'UTF-8')
        );
      }
    }
    catch (\Exception $e) {
      // Aggregator tables may not exist; skip news section.
    }

    $form['news_fieldset'] = [
      '#type' => 'details',
      '#title' => $this->t('News Items'),
      '#description' => $this->t('Select which RSS articles to include.'),
      '#open' => FALSE,
    ];
    foreach ($news_by_date as $date_key => $date_data) {
      $form['news_fieldset']['news_date_' . $date_key] = [
        '#type' => 'details',
        '#title' => $date_data['label'],
        '#open' => TRUE,
      ];
      $form['news_fieldset']['news_date_' . $date_key]['news_items_' . $date_key] = [
        '#type' => 'checkboxes',
        '#options' => $date_data['items'],
        '#default_value' => [],
      ];
    }

    // --- External Podcast Episodes (from podcast RSS feeds) ---
    $external_podcast_options = [];
    if (!empty($podcast_feed_ids)) {
      try {
        $timestamp = strtotime('-14 days');
        $ext_results = $this->database->select('aggregator_item', 'ai')
          ->fields('ai', ['iid', 'title', 'link', 'timestamp', 'fid'])
          ->condition('ai.fid', $podcast_feed_ids, 'IN')
          ->condition('ai.timestamp', $timestamp, '>')
          ->orderBy('ai.timestamp', 'DESC')
          ->execute()
          ->fetchAll();

        foreach ($ext_results as $item) {
          $feed_name = $this->database->select('aggregator_feed', 'af')
            ->fields('af', ['title'])
            ->condition('af.fid', $item->fid)
            ->execute()
            ->fetchField();
          $external_podcast_options[$item->iid] = Markup::create(
            '<a href="' . htmlspecialchars($item->link, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">' . htmlspecialchars($item->title, ENT_QUOTES, 'UTF-8') . '</a>'
            . ' — ' . htmlspecialchars($feed_name ?: 'Unknown', ENT_QUOTES, 'UTF-8')
            . ' (' . date('M j, Y', $item->timestamp) . ')'
          );
        }
      }
      catch (\Exception $e) {
        // Aggregator tables may not exist.
      }
    }

    $form['external_podcast_fieldset'] = [
      '#type' => 'details',
      '#title' => $this->t('External Podcast Episodes'),
      '#open' => FALSE,
      '#description' => empty($podcast_feed_ids)
        ? $this->t('No podcast feeds configured. Visit <a href="/admin/config/dynasty/newsletter">Newsletter Settings</a> to tag RSS feeds as podcast feeds.')
        : '',
    ];
    $form['external_podcast_fieldset']['external_podcast_episodes'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('External Podcast Episodes'),
      '#description' => $this->t('Select episodes from external podcast RSS feeds to include.'),
      '#options' => $external_podcast_options,
      '#default_value' => [],
    ];

    // --- Site Podcast Episodes ---
    $podcast_options = [];

    $result = $this->entityTypeManager
      ->getStorage('node')
      ->getQuery()
      ->condition('type', 'podcast_episode')
      ->sort('created', 'DESC')
      ->range(0, 10)
      ->accessCheck(TRUE)
      ->execute();
    $podcast_nids = array_values($result);

    if (!empty($podcast_nids)) {
      $podcasts = $this->entityTypeManager->getStorage('node')->loadMultiple($podcast_nids);
      foreach ($podcast_nids as $nid) {
        if (isset($podcasts[$nid])) {
          $podcast = $podcasts[$nid];
          $season = $podcast->get('field_season')->value;
          $episode = $podcast->get('field_episode')->value;
          $podcast_options[$nid] = 'S' . $season . 'E' . $episode . ' — ' . $podcast->getTitle();
        }
      }
    }

    $form['podcast_fieldset'] = [
      '#type' => 'details',
      '#title' => $this->t('Site Podcast Episodes'),
      '#open' => FALSE,
    ];
    $form['podcast_fieldset']['podcast_episodes'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Site Podcast Episodes'),
      '#description' => $this->t('Select episodes from this website to include.'),
      '#options' => $podcast_options,
      '#default_value' => [],
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Generate Newsletter'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    try {
      $news_iids = [];
      foreach ($form_state->getValues() as $key => $value) {
        if (str_starts_with($key, 'news_items_') && is_array($value)) {
          $news_iids = array_merge($news_iids, array_values(array_filter($value)));
        }
      }
      $podcast_nids = array_values(array_filter($form_state->getValue('podcast_episodes')));
      $external_podcast_iids = array_values(array_filter($form_state->getValue('external_podcast_episodes')));

      // Build newsletter content with manually selected items.
      $html = $this->contentBuilder->buildNewsletterContent([
        'news_iids' => $news_iids,
        'podcast_nids' => $podcast_nids,
        'external_podcast_iids' => $external_podcast_iids,
      ]);

      // Create Simplenews issue node
      $newsletter = Node::create([
        'type' => 'simplenews_issue',
        'title' => $form_state->getValue('title'),
        'body' => [
          'value' => $html,
          'format' => 'full_html',
        ],
        'simplenews_issue' => [
          'target_id' => 'patriots_dynasty_weekly',
        ],
        'status' => 0, // Unpublished draft
      ]);
      $newsletter->save();

      $this->messenger()->addStatus($this->t('Newsletter draft created: <a href=":url">@title</a>', [
        ':url' => $newsletter->toUrl('edit-form')->toString(),
        '@title' => $newsletter->getTitle(),
      ]));

      // Redirect to the newsletter edit form
      $form_state->setRedirect('entity.node.edit_form', ['node' => $newsletter->id()]);

    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to generate newsletter: @message', [
        '@message' => $e->getMessage(),
      ]));
      $this->getLogger('dynasty_newsletter')->error('Newsletter generation failed: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

}
