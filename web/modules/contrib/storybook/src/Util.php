<?php

namespace Drupal\storybook;

use Drupal\Core\Routing\RouteObjectInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Utility class with shared methods.
 */
class Util {

  /**
   * Checks if a request is for the render controller.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request to check.
   *
   * @return bool
   *   TRUE if it is for the render controller.
   */
  public static function isRenderController(Request $request): bool {
    $route_name = $request->attributes->get(RouteObjectInterface::ROUTE_NAME);
    return $route_name === 'storybook.render_story';
  }

}
