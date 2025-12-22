<?php

namespace Drupal\dynasty_advent\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Controller for the Advent Calendar.
 */
class AdventCalendarController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * Constructs an AdventCalendarController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, RendererInterface $renderer) {
    $this->entityTypeManager = $entity_type_manager;
    $this->renderer = $renderer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('renderer')
    );
  }

  /**
   * Displays the advent calendar.
   */
  public function calendar() {
    // Get current date info.
    $current_date = new \DateTime('now', new \DateTimeZone('America/New_York'));
    $current_month = (int) $current_date->format('n');
    $current_day = (int) $current_date->format('j');
    $current_year = (int) $current_date->format('Y');

    // Check if it's December.
    $is_december = $current_month === 12;

    // Generate random door order (but consistent based on year).
    $doors = range(1, 24);
    mt_srand($current_year);
    shuffle($doors);
    mt_srand(); // Reset random seed.

    $build = [
      '#theme' => 'dynasty_advent_calendar',
      '#doors' => $doors,
      '#current_day' => $current_day,
      '#is_december' => $is_december,
      '#attached' => [
        'library' => [
          'dynasty_advent/advent_calendar',
        ],
      ],
    ];

    return $build;
  }

  /**
   * Gets the content for a specific door via AJAX.
   */
  public function getDoorContent($day) {
    $day = (int) $day;

    // Validate day.
    if ($day < 1 || $day > 24) {
      return new JsonResponse(['error' => 'Invalid day'], 400);
    }

    // Check if the door can be opened.
    $current_date = new \DateTime('now', new \DateTimeZone('America/New_York'));
    $current_month = (int) $current_date->format('n');
    $current_day = (int) $current_date->format('j');

    // Only allow opening if it's December and the day has arrived.
    if ($current_month !== 12 || $current_day < $day) {
      return new JsonResponse(['error' => 'Door is locked'], 403);
    }

    // Load the advent calendar item for this day.
    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->condition('type', 'advent_calendar_item')
      ->condition('status', 1)
      ->condition('field_advent_day', $day)
      ->accessCheck(TRUE)
      ->range(0, 1);

    $nids = $query->execute();

    if (empty($nids)) {
      return new JsonResponse(['error' => 'No content found for this day'], 404);
    }

    $node = $storage->load(reset($nids));

    // Build the response.
    $view_builder = $this->entityTypeManager->getViewBuilder('node');
    $build = $view_builder->view($node, 'advent_modal');

    $rendered = $this->renderer->renderRoot($build);

    return new JsonResponse([
      'content' => $rendered,
      'title' => $node->getTitle(),
    ]);
  }

}
