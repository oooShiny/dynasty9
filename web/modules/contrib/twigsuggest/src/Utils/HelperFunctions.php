<?php

namespace Drupal\twigsuggest\Utils;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class HelperFunctions.
 *
 * @package Drupal\twigsuggest\Utils
 */
class HelperFunctions {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * HelperFunctions constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, RouteMatchInterface $route_match) {
    $this->entityTypeManager = $entity_type_manager;
    $this->routeMatch = $route_match;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_route_match')
    );
  }

  /**
   * Get current node.
   *
   * Helper function to return current node no matter if viewing full node,
   * node preview or revision.
   *
   * @return bool|\Drupal\node\Entity\Node
   *   Returns the current node object or FALSE otherwise.
   */
  public function getCurrentNode() {

    $node = FALSE;

    if ($this->routeMatch->getRouteName() == 'entity.node.canonical') {
      $node = $this->routeMatch->getParameter('node');
    }
    elseif ($this->routeMatch->getRouteName() == 'entity.node.revision') {
      // @todo https://www.drupal.org/i/2730631 will allow to use the upcasted
      //   node revision object.
      $node_revision = $this->routeMatch->getParameter('node_revision');
      if ($node_revision instanceof NodeInterface) {
        $node = $node_revision;
      }
      elseif ($node_revision) {
        $node = $this->entityTypeManager->getStorage('node')->loadRevision($node_revision);
      }
    }
    elseif ($this->routeMatch->getRouteName() == 'entity.node.preview') {
      $node = $this->routeMatch->getParameter('node_preview');
    }

    return $node;
  }

}
