<?php

namespace Drupal\markdownify\Routing;

use Drupal\Core\Routing\FilterInterface;
use Drupal\Core\Routing\RequestFormatRouteFilter;
use Drupal\markdownify\Utility\MarkdownifyPath;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouteCollection;

/**
 * Filters routes to handle `.md` requests by dynamically setting the format.
 *
 * This route filter intercepts requests that end with `.md` and ensures they
 * are assigned a `markdown` format. This enables the application of custom
 * logic for Markdown content while preserving other formats.
 */
class MarkdownRequestFormatRouteFilter extends RequestFormatRouteFilter implements FilterInterface {

  /**
   * {@inheritDoc}
   *
   * This method ensures that requests ending with `.md` are dynamically
   * assigned the `markdown` format. For other requests, the default parent
   * logic applies.
   */
  public function filter(RouteCollection $collection, Request $request) {
    // Determine the request format if it's not explicitly set.
    if ($this->isRequestFormatNotExplicitlySet($request)) {
      $format = $this->determineFormatForRequest($collection, $request);
      $request->setRequestFormat($format);
    }
    // Delegate further filtering to the parent class.
    return parent::filter($collection, $request);
  }

  /**
   * Checks if the request format is not explicitly set.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current HTTP request.
   *
   * @return bool
   *   TRUE if the request format is not set, FALSE otherwise.
   */
  protected function isRequestFormatNotExplicitlySet(Request $request): bool {
    return $request->getRequestFormat(NULL) === NULL;
  }

  /**
   * Resolves the format for a given request.
   *
   * Requests ending with `.md` or starting with the Markdownify prefix
   * are assigned the `markdown` format. Otherwise, the parent's logic is used.
   *
   * @param \Symfony\Component\Routing\RouteCollection $collection
   *   The collection of routes being filtered.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current HTTP request.
   *
   * @return string
   *   The determined request format.
   */
  protected function determineFormatForRequest(RouteCollection $collection, Request $request): string {
    // Check if the request path or headers are targeting Markdown.
    if (MarkdownifyPath::isMarkdownRequest($request)) {
      return 'markdown';
    }
    // Fallback to the parent's default logic for non-Markdown routes.
    return parent::getDefaultFormat($collection);
  }

}
