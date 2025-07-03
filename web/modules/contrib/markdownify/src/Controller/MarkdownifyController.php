<?php

namespace Drupal\markdownify\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\markdownify\MarkdownifyEntityConverterInterface;
use Drupal\markdownify\MarkdownResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for rendering Markdown versions of content.
 *
 * This controller handles requests to convert entities to Markdown format.
 * It uses the MarkdownConverter service to transform entity data into
 * Markdown and returns the result as a response.
 */
class MarkdownifyController extends ControllerBase {

  /**
   * The Markdownify Entity Converter service.
   *
   * @var \Drupal\markdownify\MarkdownifyEntityConverterInterface
   */
  protected MarkdownifyEntityConverterInterface $converter;

  /**
   * Constructs a new MarkdownifyController object.
   *
   * @param \Drupal\markdownify\MarkdownifyEntityConverterInterface $converter
   *   The service for converting entities to Markdown.
   */
  public function __construct(MarkdownifyEntityConverterInterface $converter) {
    $this->converter = $converter;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('markdownify.entity_converter'),
    );
  }

  /**
   * Renders Markdown for the given entity.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   * @param string $view_mode
   *   (optional) The view mode used for rendering the entity. Defaults 'full'.
   * @param string|null $langcode
   *   (optional) The language code for rendering the entity. Defaults to the
   *   current content language.
   *
   * @return \Drupal\markdownify\MarkdownResponse
   *   The Markdown response.
   */
  public function render(RouteMatchInterface $route_match, string $view_mode = 'full', ?string $langcode = NULL): MarkdownResponse {
    // Set the cacheable metadata.
    $metadata = new BubbleableMetadata();
    $metadata->addCacheContexts(['languages', 'url.query_args:_format']);
    // Get the entity from the route match.
    $entity = $this->getEntityFromRouteMatch($route_match);
    // Convert the entity to Markdown.
    $markdown = $this->converter->convertEntityToMarkdown($entity, $view_mode, $langcode, $metadata);
    // Prepare response with cacheable dependencies.
    $response = new MarkdownResponse($markdown, 200, []);
    $response->addCacheableDependency($metadata);
    // Return the Markdown response.
    return $response;
  }

  /**
   * Gets the title for the Markdown route.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   *
   * @return string
   *   The title of the entity.
   */
  public function title(RouteMatchInterface $route_match): string {
    $entity = $this->getEntityFromRouteMatch($route_match);
    return $entity->label();
  }

  /**
   * Retrieves entity from route match.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The entity object as determined from the passed-in route match.
   */
  protected function getEntityFromRouteMatch(RouteMatchInterface $route_match): EntityInterface {
    $entity_type = $route_match->getRouteObject()->getRequirement('_entity_type');
    return $route_match->getParameter($entity_type);
  }

}
