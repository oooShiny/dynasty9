<?php

namespace Drupal\dynasty_newsletter\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;

/**
 * Controller for Dynasty Newsletter dashboard.
 */
class DashboardController extends ControllerBase {

  /**
   * Display the newsletter dashboard.
   *
   * @return array
   *   Render array.
   */
  public function dashboard() {
    $build = [];

    // Dashboard header
    $build['header'] = [
      '#markup' => '<h1>' . $this->t('Dynasty Newsletter Dashboard') . '</h1><p>' . $this->t('Manage newsletters, subscribers, and content sources for Patriots Dynasty Weekly.') . '</p>',
    ];

    // Quick Actions section
    $build['quick_actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['dashboard-section', 'quick-actions']],
    ];

    $build['quick_actions']['title'] = [
      '#markup' => '<h2>' . $this->t('Quick Actions') . '</h2>',
    ];

    $build['quick_actions']['actions'] = [
      '#theme' => 'item_list',
      '#list_type' => 'ul',
      '#items' => [
        [
          '#markup' => '<strong>' . Link::fromTextAndUrl($this->t('Generate New Newsletter'), Url::fromRoute('dynasty_newsletter.generate'))->toString() . '</strong> - Create a new newsletter draft',
        ],
        [
          '#markup' => Link::fromTextAndUrl($this->t('View All Newsletters'), Url::fromRoute('view.dynasty_newsletters.page_all'))->toString() . ' - See drafts and sent newsletters',
        ],
        [
          '#markup' => Link::fromTextAndUrl($this->t('Manage Subscribers'), Url::fromRoute('view.dynasty_subscribers.page_1'))->toString() . ' - View and manage newsletter subscribers',
        ],
      ],
    ];

    // Configuration section
    $build['configuration'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['dashboard-section', 'configuration']],
    ];

    $build['configuration']['title'] = [
      '#markup' => '<h2>' . $this->t('Configuration') . '</h2>',
    ];

    $build['configuration']['links'] = [
      '#theme' => 'item_list',
      '#list_type' => 'ul',
      '#items' => [
        [
          '#markup' => Link::fromTextAndUrl($this->t('Newsletter Settings'), Url::fromRoute('dynasty_newsletter.settings'))->toString() . ' - Frequency, timing, and content limits',
        ],
        [
          '#markup' => Link::fromTextAndUrl($this->t('RSS Feed Settings'), Url::fromRoute('dynasty_newsletter.rss_settings'))->toString() . ' - Configure news feed sources',
        ],
        [
          '#markup' => Link::fromTextAndUrl($this->t('Simplenews Configuration'), Url::fromRoute('simplenews.newsletter_list'))->toString() . ' - Manage newsletters and settings',
        ],
        [
          '#markup' => Link::fromTextAndUrl($this->t('Manage RSS Feeds'), Url::fromRoute('aggregator.admin_overview'))->toString() . ' - Add, edit, or remove RSS feeds',
        ],
      ]
    ];

    // Content Sources section
    $build['sources'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['dashboard-section', 'content-sources']],
    ];

    $build['sources']['title'] = [
      '#markup' => '<h2>' . $this->t('Content Sources') . '</h2>',
    ];

    // Get RSS feed count
    $feed_count = count($this->entityTypeManager()->getStorage('aggregator_feed')->loadMultiple());

    // Get recent items count
    $database = \Drupal::database();
    $recent_items = $database->select('aggregator_item', 'ai')
      ->condition('ai.timestamp', strtotime('-7 days'), '>')
      ->countQuery()
      ->execute()
      ->fetchField();

    $build['sources']['stats'] = [
      '#theme' => 'item_list',
      '#list_type' => 'ul',
      '#items' => [
        $this->t('<strong>@count RSS Feeds</strong> configured and monitored', ['@count' => $feed_count]),
        $this->t('<strong>@count News Items</strong> collected in the last 7 days', ['@count' => $recent_items]),
//        Link::fromTextAndUrl($this->t('Refresh RSS Feeds Now'), Url::fromRoute('aggregator.feed_refresh'))->toString() . ' (via Aggregator)',
      ],
    ];

    // Subscriber Stats section
    $build['subscribers'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['dashboard-section', 'subscribers']],
    ];

    $build['subscribers']['title'] = [
      '#markup' => '<h2>' . $this->t('Subscribers') . '</h2>',
    ];


    // Recent Activity section
    $build['activity'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['dashboard-section', 'recent-activity']],
    ];

    $build['activity']['title'] = [
      '#markup' => '<h2>' . $this->t('Recent Activity') . '</h2>',
    ];

    // Get draft newsletter count
    $draft_count = $this->entityTypeManager()->getStorage('node')->getQuery()
      ->condition('type', 'simplenews_issue')
      ->condition('status', 0)
      ->accessCheck(TRUE)
      ->count()
      ->execute();

    // Get sent newsletter count (last 30 days)
    $sent_count = $this->entityTypeManager()->getStorage('node')->getQuery()
      ->condition('type', 'simplenews_issue')
      ->condition('status', 1)
      ->condition('created', strtotime('-30 days'), '>')
      ->accessCheck(TRUE)
      ->count()
      ->execute();

    $build['activity']['stats'] = [
      '#theme' => 'item_list',
      '#list_type' => 'ul',
      '#items' => [
        Link::fromTextAndUrl($this->t('<strong>@count Draft Newsletters</strong> awaiting review', ['@count' => $draft_count]), Url::fromRoute('view.dynasty_newsletters.page_drafts'))->toString(),
        Link::fromTextAndUrl($this->t('<strong>@count Newsletters Sent</strong> in the last 30 days', ['@count' => $sent_count]), Url::fromRoute('view.dynasty_newsletters.page_sent'))->toString(),
        Link::fromTextAndUrl($this->t('View All Newsletters'), Url::fromRoute('view.dynasty_newsletters.page_all'))->toString(),
      ],
    ];

    // Drush Commands section
    $build['drush'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['dashboard-section', 'drush-commands']],
    ];

    $build['drush']['title'] = [
      '#markup' => '<h2>' . $this->t('Drush Commands') . '</h2>',
    ];

    $build['drush']['commands'] = [
      '#markup' => '<ul>
        <li><code>ddev drush dynasty-newsletter:generate</code> - Generate a newsletter draft</li>
        <li><code>ddev drush dynasty-newsletter:backfill-dates</code> - Backfill podcast publication dates</li>
        <li><code>ddev drush aggregator:refresh</code> - Refresh RSS feeds</li>
        <li><code>ddev drush simplenews:subscriber-list</code> - List newsletter subscribers</li>
      </ul>',
    ];

    return [
      '#theme' => 'newsletter_dashboard',
      '#sections' => $build];
  }

}
