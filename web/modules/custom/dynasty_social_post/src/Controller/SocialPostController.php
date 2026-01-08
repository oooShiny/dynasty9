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

}
