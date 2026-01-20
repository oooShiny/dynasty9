<?php

namespace Drupal\dynasty_social_post\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\dynasty_social_post\Service\BlueskyService;
use Drupal\dynasty_social_post\Service\YouTubeService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for social posting actions.
 */
class SocialPostController extends ControllerBase {

  /**
   * The Bluesky service.
   *
   * @var \Drupal\dynasty_social_post\Service\BlueskyService
   */
  protected $blueskyService;

  /**
   * The YouTube service.
   *
   * @var \Drupal\dynasty_social_post\Service\YouTubeService
   */
  protected $youtubeService;

  /**
   * Constructs a SocialPostController object.
   *
   * @param \Drupal\dynasty_social_post\Service\BlueskyService $bluesky_service
   *   The Bluesky service.
   * @param \Drupal\dynasty_social_post\Service\YouTubeService $youtube_service
   *   The YouTube service.
   */
  public function __construct(BlueskyService $bluesky_service, YouTubeService $youtube_service) {
    $this->blueskyService = $bluesky_service;
    $this->youtubeService = $youtube_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('dynasty_social_post.bluesky'),
      $container->get('dynasty_social_post.youtube')
    );
  }

  /**
   * Posts a random highlight to enabled platforms immediately.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect back to settings page.
   */
  public function postNow() {
    $config = $this->config('dynasty_social_post.settings');
    $bluesky_enabled = $config->get('enable_bluesky') ?? TRUE;
    $youtube_enabled = $config->get('enable_youtube') ?? FALSE;

    $bluesky_success = FALSE;
    $youtube_success = FALSE;

    // Post to Bluesky if enabled.
    if ($bluesky_enabled) {
      try {
        $bluesky_success = $this->blueskyService->postRandomHighlight();
        if ($bluesky_success) {
          $this->messenger()->addStatus($this->t('Successfully posted a highlight to Bluesky!'));
        }
        else {
          $this->messenger()->addWarning($this->t('Failed to post to Bluesky. Check the logs for details.'));
        }
      }
      catch (\Exception $e) {
        $this->messenger()->addError($this->t('Error posting to Bluesky: @message', ['@message' => $e->getMessage()]));
        \Drupal::logger('dynasty_social_post')->error('Error in manual Bluesky post: @message', ['@message' => $e->getMessage()]);
      }
    }

    // Post to YouTube if enabled and configured.
    if ($youtube_enabled && $this->youtubeService->isConfigured()) {
      try {
        $youtube_success = $this->youtubeService->postHighlight();
        if ($youtube_success) {
          $this->messenger()->addStatus($this->t('Successfully posted a highlight to YouTube!'));
        }
        else {
          $this->messenger()->addWarning($this->t('Failed to post to YouTube. Check the logs for details.'));
        }
      }
      catch (\Exception $e) {
        $this->messenger()->addError($this->t('Error posting to YouTube: @message', ['@message' => $e->getMessage()]));
        \Drupal::logger('dynasty_social_post')->error('Error in manual YouTube post: @message', ['@message' => $e->getMessage()]);
      }
    }
    elseif ($youtube_enabled && !$this->youtubeService->isConfigured()) {
      $this->messenger()->addWarning($this->t('YouTube is enabled but not authorized. Please authorize YouTube first.'));
    }

    // Update last post time if at least one platform succeeded.
    if ($bluesky_success || $youtube_success) {
      \Drupal::state()->set('dynasty_social_post.last_post_time', \Drupal::time()->getRequestTime());
    }

    return new RedirectResponse('/admin/config/dynasty/social-post');
  }

  /**
   * Handles YouTube OAuth callback.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect back to settings page.
   */
  public function youtubeCallback(Request $request) {
    $code = $request->query->get('code');
    $error = $request->query->get('error');

    if ($error) {
      $this->messenger()->addError($this->t('YouTube authorization failed: @error', ['@error' => $error]));
      \Drupal::logger('dynasty_social_post')->error('YouTube OAuth error: @error', ['@error' => $error]);
      return new RedirectResponse('/admin/config/dynasty/social-post');
    }

    if (!$code) {
      $this->messenger()->addError($this->t('No authorization code received from YouTube.'));
      return new RedirectResponse('/admin/config/dynasty/social-post');
    }

    // Exchange the code for tokens.
    $success = $this->youtubeService->exchangeCodeForTokens($code);

    if ($success) {
      $channel_info = $this->youtubeService->getChannelInfo();
      if ($channel_info) {
        $this->messenger()->addStatus($this->t('Successfully connected to YouTube channel: @channel', [
          '@channel' => $channel_info['title'],
        ]));
      }
      else {
        $this->messenger()->addStatus($this->t('Successfully connected to YouTube!'));
      }
    }
    else {
      $this->messenger()->addError($this->t('Failed to complete YouTube authorization. Please try again.'));
    }

    return new RedirectResponse('/admin/config/dynasty/social-post');
  }

  /**
   * Disconnects YouTube account.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect back to settings page.
   */
  public function youtubeDisconnect() {
    $this->youtubeService->disconnect();
    $this->messenger()->addStatus($this->t('YouTube account has been disconnected.'));
    return new RedirectResponse('/admin/config/dynasty/social-post');
  }

  /**
   * Displays a list of posted highlights.
   *
   * @return array
   *   Render array for the page.
   */
  public function postedList() {
    $build = [];

    // Bluesky posted highlights.
    $bluesky_posted = \Drupal::state()->get('dynasty_social_post.posted_highlights', []);
    $build['bluesky'] = [
      '#type' => 'details',
      '#title' => $this->t('Bluesky Posted Highlights (@count)', ['@count' => count($bluesky_posted)]),
      '#open' => TRUE,
    ];

    if (empty($bluesky_posted)) {
      $build['bluesky']['content'] = [
        '#markup' => $this->t('No highlights have been posted to Bluesky yet.'),
      ];
    }
    else {
      $build['bluesky']['content'] = $this->buildHighlightTable($bluesky_posted);
    }

    // YouTube posted highlights.
    $youtube_posted = \Drupal::state()->get('dynasty_social_post.youtube_posted_highlights', []);
    $build['youtube'] = [
      '#type' => 'details',
      '#title' => $this->t('YouTube Posted Highlights (@count)', ['@count' => count($youtube_posted)]),
      '#open' => TRUE,
    ];

    if (empty($youtube_posted)) {
      $build['youtube']['content'] = [
        '#markup' => $this->t('No highlights have been posted to YouTube yet.'),
      ];
    }
    else {
      $build['youtube']['content'] = $this->buildHighlightTable($youtube_posted);
    }

    return $build;
  }

  /**
   * Builds a table of posted highlights.
   *
   * @param array $nids
   *   Array of node IDs.
   *
   * @return array
   *   Render array for the table.
   */
  protected function buildHighlightTable(array $nids) {
    $node_storage = $this->entityTypeManager()->getStorage('node');
    $highlights = $node_storage->loadMultiple($nids);

    $rows = [];
    foreach ($highlights as $highlight) {
      if (!$highlight) {
        continue;
      }

      $season = $highlight->get('field_season')->value ?? 'N/A';
      $week_entity = $highlight->get('field_week')->entity;
      $week = $week_entity ? $week_entity->getName() : 'N/A';
      $opponent_entity = $highlight->get('field_opponent')->entity;
      $opponent = $opponent_entity ? $opponent_entity->getTitle() : 'N/A';

      $rows[] = [
        $highlight->id(),
        [
          'data' => [
            '#type' => 'link',
            '#title' => $highlight->getTitle(),
            '#url' => $highlight->toUrl(),
          ],
        ],
        $season,
        $week,
        $opponent,
      ];
    }

    return [
      '#theme' => 'table',
      '#header' => [
        $this->t('ID'),
        $this->t('Title'),
        $this->t('Season'),
        $this->t('Week'),
        $this->t('Opponent'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No highlights found.'),
    ];
  }

}
