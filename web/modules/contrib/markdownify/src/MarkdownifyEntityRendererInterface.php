<?php

namespace Drupal\markdownify;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Render\BubbleableMetadata;

/**
 * Interface for rendering Drupal entities as HTML.
 *
 * This interface defines a contract for rendering entities into HTML,
 * which can then be used for various purposes, such as generating
 * Markdown or displaying rendered output.
 */
interface MarkdownifyEntityRendererInterface {

  /**
   * Renders a given entity as an HTML string.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to render as HTML.
   * @param string $view_mode
   *   (optional) The view mode used for rendering the entity. Defaults 'full'.
   * @param string|null $langcode
   *   (optional) The language code for rendering the entity. Defaults to the
   *   current content language.
   * @param \Drupal\Core\Render\BubbleableMetadata|null $metadata
   *   (optional) Object to collect entity bubbleable metadata.
   *
   * @return string
   *   The rendered HTML output, or an empty string if rendering fails.
   */
  public function toHtml(EntityInterface $entity, string $view_mode = 'full', ?string $langcode = NULL, ?BubbleableMetadata $metadata = NULL): string;

}
