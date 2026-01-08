<?php

namespace Drupal\dynasty_social_post\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\dynasty_social_post\Service\BlueskyService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for manual posting actions.
 */
class SocialPostController extends ControllerBase {

  /**
   * The Bluesky service.
   *
   * @var \Drupal\dynasty_social_post\Service\BlueskyService
   */
  protected $blueskyService;

  /**
   * Constructs a SocialPostController object.
   *
   * @param \Drupal\dynasty_social_post\Service\BlueskyService $bluesky_service
   *   The Bluesky service.
   */
  public function __construct(BlueskyService $bluesky_service) {
    $this->blueskyService = $bluesky_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('dynasty_social_post.bluesky')
    );
  }

  /**
   * Posts a random highlight to Bluesky immediately.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect back to settings page.
   */
  public function postNow() {
    try {
      $result = $this->blueskyService->postRandomHighlight();

      if ($result) {
        $this->messenger()->addStatus($this->t('Successfully posted a highlight to Bluesky!'));
        \Drupal::state()->set('dynasty_social_post.last_post_time', \Drupal::time()->getRequestTime());
      }
      else {
        $this->messenger()->addError($this->t('Failed to post to Bluesky. Check the logs for details.'));
      }
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Error posting to Bluesky: @message', ['@message' => $e->getMessage()]));
      \Drupal::logger('dynasty_social_post')->error('Error in manual post: @message', ['@message' => $e->getMessage()]);
    }

    return new RedirectResponse('/admin/config/dynasty/social-post');
  }

  /**
   * Displays a list of posted highlights.
   *
   * @return array
   *   Render array for the page.
   */
  public function postedList() {
    $posted_highlights = \Drupal::state()->get('dynasty_social_post.posted_highlights', []);

    if (empty($posted_highlights)) {
      return [
        '#markup' => $this->t('No highlights have been posted yet.'),
      ];
    }

    // Load the highlight nodes.
    $node_storage = $this->entityTypeManager()->getStorage('node');
    $highlights = $node_storage->loadMultiple($posted_highlights);

    $rows = [];
    foreach ($highlights as $highlight) {
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
      '#empty' => $this->t('No highlights have been posted yet.'),
      '#caption' => $this->t('Total posted: @count', ['@count' => count($posted_highlights)]),
    ];
  }

}
