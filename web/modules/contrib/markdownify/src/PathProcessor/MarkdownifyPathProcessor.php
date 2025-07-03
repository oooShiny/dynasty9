<?php

namespace Drupal\markdownify\PathProcessor;

use Drupal\Core\PathProcessor\InboundPathProcessorInterface;
use Drupal\markdownify\Utility\MarkdownifyPath;
use Symfony\Component\HttpFoundation\Request;

/**
 * Processes inbound paths to support Markdown-specific routes.
 *
 * This class modifies inbound paths ending in ".md" by:
 * - Stripping the ".md" suffix.
 * - Prepending the "/markdownify" prefix to the path.
 *
 * @see \Drupal\Core\PathProcessor\PathProcessorInterface
 */
class MarkdownifyPathProcessor implements InboundPathProcessorInterface {

  /**
   * {@inheritdoc}
   *
   * Modifies inbound paths for Markdown routes.
   *
   * If the inbound path ends with ".md", this method:
   * - Ensures the path is valid and ends with ".md".
   * - Converts the path to a Markdown-specific format.
   */
  public function processInbound($path, Request $request): string {
    // Ensure the normalized path is a non-empty string.
    if (!$this->isPathValid($path)) {
      return $path;
    }
    // Check if the path ends with ".md".
    if (!MarkdownifyPath::isMarkdownifyFormat($path, TRUE)) {
      return $path;
    }
    // Convert the path to a Markdown-specific format.
    return MarkdownifyPath::convertToMarkdownifyPath($path, TRUE);
  }

  /**
   * Validates that the path is a non-empty string.
   *
   * @param mixed $path
   *   The path to validate.
   *
   * @return bool
   *   TRUE if the path is a valid non-empty string, FALSE otherwise.
   */
  protected function isPathValid($path): bool {
    return is_string($path) && $path !== '';
  }

}
