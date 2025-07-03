<?php

namespace Drupal\markdownify;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Render\BubbleableMetadata;

/**
 * Interface for converting Drupal entities to Markdown format.
 *
 * This interface defines a contract for transforming entities into their
 * Markdown representation. It is intended for use cases where content
 * needs to be converted from a structured entity format to Markdown for
 * compatibility with Markdown-based workflows, APIs, or file exports.
 */
interface MarkdownifyEntityConverterInterface {

  /**
   * Converts a given Drupal entity into Markdown format.
   *
   * This method accepts a Drupal entity and generates a Markdown representation
   * of its content. The exact structure and format of the Markdown output
   * depend on the entity type and any implemented transformations.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to convert.
   * @param string $view_mode
   *   (optional) The view mode used for rendering the entity. Defaults 'full'.
   * @param string|null $langcode
   *   (optional) The language code for rendering the entity. Defaults to the
   *   current content language.
   * @param \Drupal\Core\Render\BubbleableMetadata|null $metadata
   *   (optional) Object to collect entity bubbleable metadata.
   *
   * @return string
   *   The Markdown string representation of the entity's content.
   */
  public function convertEntityToMarkdown(EntityInterface $entity, string $view_mode = 'full', ?string $langcode = NULL, ?BubbleableMetadata $metadata = NULL): string;

}
