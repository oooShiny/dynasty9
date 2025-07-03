<?php

namespace Drupal\markdownify;

use Drupal\Core\Cache\CacheableResponse;

/**
 * Represents an HTTP response in Markdown format with cacheability support.
 *
 * This class extends CacheableResponse to provide a response formatted in
 * Markdown, ensuring proper HTTP headers and cacheability handling.
 */
class MarkdownResponse extends CacheableResponse {

  /**
   * Constructs a new MarkdownResponse object.
   *
   * @param string $content
   *   The response content in Markdown format.
   * @param int $status
   *   The response status code (default is 200 OK).
   * @param array $headers
   *   An optional array of additional response headers.
   *
   * @throws \InvalidArgumentException
   *   If the HTTP status code is invalid.
   */
  public function __construct(string $content = '', int $status = 200, array $headers = []) {
    parent::__construct($content, $status, $headers);
    // Ensure the response has the correct Markdown content type.
    $this->headers->set('Content-Type', 'text/markdown; charset=utf-8');
    // Send the "Vary: Accept"  header in the response to prevent any issues
    // with intermediary HTTP caches.
    $this->headers->set('Vary', 'Accept');
  }

}
