<?php

namespace Drupal\dynasty_search\StackMiddleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Drops irrelevant query strings from search page-shell requests.
 *
 * The /search/* pages are static shells: every filter lives in the query
 * string, but only the browser reads it (each js/*-search.js reads
 * location.search itself). The server-rendered HTML is the same for every
 * query string. Drupal's page cache, though, keys on the full request URI.
 * Without this middleware, each distinct query string got its own permanent
 * page-cache entry. Bot traffic sending randomized filter URLs (a ~7k-IP
 * botnet replaying the retired Search API facet format,
 * ?season[2009]=2009&score_differential[min]=...) grew Redis to 1.8GB and
 * ~450k keys on production in Nov 2026. That led to the out-of-memory /
 * full-disk outage.
 *
 * Runs before page_cache (priority 200) and rebuilds the request with only
 * the query keys the server-side controller actually reads. Every other
 * variant then shares one page-cache entry. The visitor's browser still
 * sees, and the JS still restores from, the original URL.
 */
class SearchPageQueryNormalizer implements HttpKernelInterface {

  /**
   * Page-shell paths => query keys their server-side controller reads.
   *
   * Keep in sync with SearchPageController: if a page starts varying its
   * server-rendered output by a query arg, list that arg here or it will be
   * silently dropped.
   */
  const PATHS = [
    '/search/games' => [],
    '/search/highlights' => [],
    // SearchPageController::playsPage() picks the initial mode server-side.
    '/search/plays' => ['mode'],
  ];

  public function __construct(protected HttpKernelInterface $httpKernel) {}

  /**
   * {@inheritdoc}
   */
  public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = TRUE): Response {
    if ($type === self::MAIN_REQUEST
      && $request->isMethodCacheable()
      && $request->server->get('QUERY_STRING', '') !== ''
      && isset(self::PATHS[$request->getPathInfo()])) {
      $request = $this->normalize($request, self::PATHS[$request->getPathInfo()]);
    }
    return $this->httpKernel->handle($request, $type, $catch);
  }

  /**
   * Returns a copy of the request carrying only the given scalar query keys.
   */
  protected function normalize(Request $request, array $keep): Request {
    $query = array_filter(
      array_intersect_key($request->query->all(), array_flip($keep)),
      'is_scalar',
    );
    ksort($query);
    $query_string = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

    $server = $request->server->all();
    $server['QUERY_STRING'] = $query_string;
    $server['REQUEST_URI'] = $request->getBaseUrl() . $request->getPathInfo()
      . ($query_string !== '' ? '?' . $query_string : '');

    // duplicate() resets the memoized requestUri/pathInfo/baseUrl, so the
    // page cache ID (scheme+host+getRequestUri()) is derived from the new
    // REQUEST_URI.
    return $request->duplicate($query, NULL, NULL, NULL, NULL, $server);
  }

}
