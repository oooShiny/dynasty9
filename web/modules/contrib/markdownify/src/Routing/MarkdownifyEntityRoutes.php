<?php

namespace Drupal\markdownify\Routing;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\markdownify\Controller\MarkdownifyController;
use Drupal\markdownify\MarkdownifySupportedEntityTypesValidatorInterface;
use Drupal\markdownify\Utility\MarkdownifyPath;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Defines dynamic routes for Markdownify.
 *
 * This class dynamically generates routes for accessing Markdownified content
 * for configured entity types that support Markdown conversion.
 */
class MarkdownifyEntityRoutes implements ContainerInjectionInterface {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Service to validate supported entity types for Markdownify.
   *
   * @var \Drupal\markdownify\MarkdownifySupportedEntityTypesValidatorInterface
   */
  protected MarkdownifySupportedEntityTypesValidatorInterface $validator;

  /**
   * The logger service.
   *
   * Used to log any errors or warnings during the route generation process.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructs a new MarkdownifyEntityRoutes object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\markdownify\MarkdownifySupportedEntityTypesValidatorInterface $validator
   *   The supported entity types validator service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, MarkdownifySupportedEntityTypesValidatorInterface $validator, LoggerInterface $logger) {
    $this->entityTypeManager = $entity_type_manager;
    $this->validator = $validator;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('markdownify.supported_entity_types.validator'),
      $container->get('logger.channel.markdownify')
    );
  }

  /**
   * Builds and returns a collection of Markdownify routes.
   *
   * Iterates over supported entity types and creates routes for each.
   *
   * @return \Symfony\Component\Routing\RouteCollection
   *   A collection of dynamically defined routes.
   */
  public function routes(): RouteCollection {
    $routes = new RouteCollection();
    foreach ($this->validator->getSupportedEntityTypes() as $entity_type) {
      if ($this->isValidMarkdownifyEntityType($entity_type)) {
        $this->addEntityMarkdownifyRoutes($routes, $entity_type);
      }
    }
    return $routes;
  }

  /**
   * Adds Markdownify routes for a specific entity type to the route collection.
   *
   * @param \Symfony\Component\Routing\RouteCollection $routes
   *   The route collection to which the routes will be added.
   * @param string $entity_type
   *   The entity type ID.
   */
  protected function addEntityMarkdownifyRoutes(RouteCollection $routes, string $entity_type): void {
    // Validates if an entity type is eligible for Markdownify.
    if (!$this->isValidMarkdownifyEntityType($entity_type)) {
      return;
    }
    // Create and add Markdownify-specific route.
    $name = 'entity.' . $entity_type . '.' . MarkdownifyPath::PATH_PREFIX;
    $route = $this->createMarkdownifyRoute($entity_type);
    $routes->add($name, $route);
    // Create and add canonical Markdown route with specific format.
    $name = 'entity.' . $entity_type . '.canonical_' . MarkdownifyPath::PATH_PREFIX;
    $route = $this->createMarkdownifyCanonicalRoute($entity_type);
    $routes->add($name, $route);
  }

  /**
   * Validates if an entity type is eligible for Markdownify.
   *
   * @param string $entity_type
   *   The entity type ID.
   *
   * @return bool
   *   TRUE if valid, FALSE otherwise.
   */
  protected function isValidMarkdownifyEntityType(string $entity_type): bool {
    $definition = $this->entityTypeManager->getDefinition($entity_type, FALSE);
    if ($definition === NULL) {
      $this->logger->error('@entity_type is configured for Markdownify, but its definition could not be loaded.', ['@entity_type' => $entity_type]);
      return FALSE;
    }
    if (!$definition->hasLinkTemplate('canonical')) {
      $this->logger->error('@entity_type is configured for Markdownify but lacks a canonical link template.', ['@entity_type' => $entity_type]);
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Creates a Markdownify-specific route for a given entity type.
   *
   * Generates a new route that appends the Markdownify-specific suffix to
   * the entity type's canonical path.
   *
   * @param string $entity_type
   *   The entity type ID.
   *
   * @return \Symfony\Component\Routing\Route
   *   The newly created Markdownify route.
   */
  protected function createMarkdownifyRoute(string $entity_type): Route {
    $canonical_path = $this->getEntityCanonicalPath($entity_type);
    $path = MarkdownifyPath::convertToMarkdownifyPath($canonical_path, FALSE);
    return $this->createRouteObject($path, $entity_type);
  }

  /**
   * Creates a canonical Markdown route for a given entity type.
   *
   * Generates a route that forces the Markdown format on the canonical path
   * of the specified entity type.
   *
   * @param string $entity_type
   *   The entity type ID.
   *
   * @return \Symfony\Component\Routing\Route
   *   The newly created canonical Markdown route.
   */
  protected function createMarkdownifyCanonicalRoute(string $entity_type): Route {
    $canonical_path = $this->getEntityCanonicalPath($entity_type);
    return $this->createRouteObject($canonical_path, $entity_type);
  }

  /**
   * Retrieves the canonical path template for an entity type.
   *
   * @param string $entity_type
   *   The entity type ID.
   *
   * @return string|null
   *   The canonical link template, or NULL if not available.
   */
  protected function getEntityCanonicalPath(string $entity_type): string {
    $definition = $this->entityTypeManager->getDefinition($entity_type);
    return $definition->getLinkTemplate('canonical');
  }

  /**
   * Builds a route object for Markdownify.
   *
   * @param string $path
   *   The route path.
   * @param string $entity_type
   *   The entity type ID.
   *
   * @return \Symfony\Component\Routing\Route
   *   The configured route object.
   */
  protected function createRouteObject(string $path, string $entity_type): Route {
    // Create the route.
    $route = new Route($path);
    // Set route defaults.
    $route->addDefaults([
      '_controller' => MarkdownifyController::class . '::render',
      '_title_callback' => MarkdownifyController::class . '::title',
      '_disable_route_normalizer' => TRUE,
    ]);
    // Ser route requirements.
    $route->addRequirements([
      '_format' => 'markdown',
      '_content_type_format' => 'markdown',
      '_entity_access' => "{$entity_type}.view",
      '_entity_type' => $entity_type,
    ]);
    // Ser route parameters.
    $parameter_definitions = [
      $entity_type => [
        'type' => 'entity:' . $entity_type,
      ],
    ];
    $route->setOption('parameters', $parameter_definitions);
    // Return the Markdownify route for the given entity type.
    return $route;
  }

}
