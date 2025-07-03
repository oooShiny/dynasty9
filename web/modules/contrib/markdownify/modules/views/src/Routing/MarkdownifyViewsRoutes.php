<?php

namespace Drupal\markdownify_views\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\markdownify\Utility\MarkdownifyPath;
use Drupal\markdownify_views\Controller\MarkdownifyViewPageController;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Defines dynamic routes for Markdownify views.
 *
 * This class dynamically generates routes for accessing Markdownified content
 * for configured entity types that support Markdown conversion.
 */
class MarkdownifyViewsRoutes extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    foreach ($collection as $route_name => $route) {
      if ($this->isViewPageRoute($route)) {
        $this->addMarkdownifyRoute($collection, $route_name, $route);
      }
    }
  }

  /**
   * Determines if a route is a view page that can be Markdownified.
   *
   * A route qualifies if:
   * - It uses the 'page' views display plugin.
   * - The expected format is 'html'.
   * - It is **not** an administrative route.
   *
   * @param \Symfony\Component\Routing\Route $route
   *   The route to check.
   *
   * @return bool
   *   TRUE if the route is a view page, FALSE otherwise.
   */
  protected function isViewPageRoute(Route $route): bool {
    $id = $route->getOption('_view_display_plugin_id');
    $format = $route->getRequirement('_format');
    $path = ltrim($route->getPath(), '/');
    $is_admin_view = str_starts_with($path, 'admin/');
    return ($id === 'page' && $format === 'html' && !$is_admin_view);
  }

  /**
   * Adds a Markdownify route to the collection based on an existing view route.
   *
   * @param \Symfony\Component\Routing\RouteCollection $collection
   *   The route collection to modify.
   * @param string $route_name
   *   The original route name.
   * @param \Symfony\Component\Routing\Route $route
   *   The original route object.
   */
  protected function addMarkdownifyRoute(RouteCollection $collection, string $route_name, Route $route): void {
    $markdown_route_name = $this->generateMarkdownRouteName($route_name);
    $markdown_route = $this->createMarkdownifyViewRoute($route);
    $collection->add($markdown_route_name, $markdown_route);
  }

  /**
   * Generates a unique name for the Markdownified version of a route.
   *
   * @param string $route_name
   *   The original route name.
   *
   * @return string
   *   The modified route name with the Markdownify prefix.
   */
  protected function generateMarkdownRouteName(string $route_name): string {
    return $route_name . '_' . MarkdownifyPath::PATH_PREFIX;
  }

  /**
   * Creates a Markdownify-specific route based on an existing view page route.
   *
   * @param \Symfony\Component\Routing\Route $view_page_route
   *   The existing view page route.
   *
   * @return \Symfony\Component\Routing\Route
   *   A new Route object configured for Markdown output.
   */
  protected function createMarkdownifyViewRoute(Route $view_page_route): Route {
    $route = clone $view_page_route;
    $this->configureMarkdownifyRoute($route, $view_page_route);
    return $route;
  }

  /**
   * Configures a cloned route for Markdownify output.
   *
   * Updates the path, controller, and format requirements to ensure the
   * route serves content in Markdown format.
   *
   * @param \Symfony\Component\Routing\Route $route
   *   The cloned route to modify.
   * @param \Symfony\Component\Routing\Route $original_route
   *   The original route that was cloned.
   */
  protected function configureMarkdownifyRoute(Route $route, Route $original_route): void {
    // Set a modified path to reflect Markdown output.
    $path = MarkdownifyPath::convertToMarkdownifyPath($original_route->getPath(), FALSE);
    $route->setPath($path);
    // Assign a controller that handles Markdown responses.
    $route->setDefault('_controller', MarkdownifyViewPageController::class . '::handle');
    // Set the title callback for the Markdown version.
    $route->setDefault('_title_callback', MarkdownifyViewPageController::class . '::getTitle');
    // Prevent automatic route normalization.
    $route->setDefault('_disable_route_normalizer', TRUE);
    // Define format requirements for Markdown output.
    $route->setRequirement('_format', 'markdown');
    $route->setRequirement('_content_type_format', 'markdown');
  }

}
