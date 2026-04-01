<?php

namespace Drupal\dynasty_newsletter\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\dynasty_newsletter\Service\NewsletterContentBuilder;
use Drupal\node\Entity\Node;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * REST-style API endpoints for remote newsletter generation.
 */
class NewsletterApiController extends ControllerBase {

  /**
   * The newsletter content builder service.
   *
   * @var \Drupal\dynasty_newsletter\Service\NewsletterContentBuilder
   */
  protected $contentBuilder;

  /**
   * Constructs a NewsletterApiController object.
   */
  public function __construct(NewsletterContentBuilder $content_builder) {
    $this->contentBuilder = $content_builder;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('dynasty_newsletter.content_builder')
    );
  }

  /**
   * Returns a pool of recent news items for local LLM curation.
   *
   * GET /api/newsletter/news-items
   * Optional query param: ?limit=20
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   */
  public function getNewsItems(Request $request): JsonResponse {
    $config = $this->config('dynasty_newsletter.settings');
    $default_limit = (int) ($config->get('llm_news_pool_size') ?? 20);
    $limit = (int) ($request->query->get('limit', $default_limit));
    $limit = max(1, min(50, $limit));

    $items = $this->contentBuilder->getNewsItemsForApi($limit);

    return new JsonResponse($items);
  }

  /**
   * Creates a newsletter draft from AI-processed news items.
   *
   * POST /api/newsletter/create-draft
   * Body: { "news_items": [ {title, link, description, source, date}, ... ] }
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   */
  public function createDraft(Request $request): JsonResponse {
    $body = json_decode($request->getContent(), TRUE);

    if (json_last_error() !== JSON_ERROR_NONE) {
      return new JsonResponse(['error' => 'Invalid JSON body.'], 400);
    }

    $news_items = $body['news_items'] ?? NULL;

    if (empty($news_items) || !is_array($news_items)) {
      return new JsonResponse(['error' => 'news_items must be a non-empty array.'], 400);
    }

    // Validate each item has the required keys.
    $required_keys = ['title', 'link', 'description', 'source', 'date'];
    foreach ($news_items as $i => $item) {
      foreach ($required_keys as $key) {
        if (!isset($item[$key])) {
          return new JsonResponse([
            'error' => "Item {$i} is missing required key '{$key}'.",
          ], 400);
        }
      }
    }

    try {
      $html = $this->contentBuilder->buildNewsletterContent([
        'pre_processed_news' => $news_items,
      ]);

      $newsletter = Node::create([
        'type' => 'simplenews_issue',
        'title' => 'Patriots Dynasty Weekly - ' . date('F j, Y'),
        'body' => [
          'value' => $html,
          'format' => 'full_html',
        ],
        'simplenews_issue' => [
          'target_id' => 'patriots_dynasty_weekly',
        ],
        'status' => 0,
      ]);
      $newsletter->save();

      $edit_url = $newsletter->toUrl('edit-form', ['absolute' => TRUE])->toString();

      return new JsonResponse([
        'nid' => $newsletter->id(),
        'title' => $newsletter->getTitle(),
        'edit_url' => $edit_url,
      ]);
    }
    catch (\Exception $e) {
      $this->getLogger('dynasty_newsletter')->error(
        'Remote draft creation failed: @message',
        ['@message' => $e->getMessage()]
      );
      return new JsonResponse(['error' => 'Failed to create draft: ' . $e->getMessage()], 500);
    }
  }

}
