<?php

namespace Drupal\markdownify;

use Drupal\Core\Render\BubbleableMetadata;

/**
 * Interface for converting HTML to Markdown format.
 *
 * This interface defines a contract for converting HTML strings into
 * Markdown. The resulting Markdown can then be used for text-based
 * outputs or storage in Markdown-compatible formats.
 */
interface MarkdownifyHtmlConverterInterface {

  /**
   * Converts a given HTML string into Markdown format.
   *
   * @param string $html
   *   The HTML content to convert.
   * @param \Drupal\Core\Render\BubbleableMetadata|null $metadata
   *   (optional) Object to collect HTML converter' bubbleable metadata.
   *
   * @return string
   *   The resulting Markdown string, or an empty string if the input is empty.
   */
  public function convert(string $html, ?BubbleableMetadata $metadata = NULL): string;

}
