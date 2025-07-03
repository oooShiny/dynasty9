<?php

/**
 * @file
 * Hooks specific to the Markdownify module.
 */

use Drupal\Core\Render\BubbleableMetadata;

/**
 * Alter the supported entity types for Markdown conversion.
 *
 * This hook allows other modules to modify the list of entity types that
 * the Markdownify module supports for conversion to Markdown.
 *
 * @param array &$supported_entity_types
 *   The list of entity types supported by the module. Default values include
 *   'node' and 'taxonomy_term'. Other modules can add or remove items from
 *   this array as needed.
 */
function hook_markdownify_supported_entity_types_alter(array &$supported_entity_types): void {
  // Example: Add a custom entity type to the supported list.
  if (\Drupal::moduleHandler()->moduleExists('custom_module')) {
    $supported_entity_types[] = 'custom_entity_type';
  }
  // Example: Remove 'taxonomy_term' from the supported list.
  if (($key = array_search('taxonomy_term', $supported_entity_types)) !== FALSE) {
    unset($supported_entity_types[$key]);
  }
}

/**
 * Alter the render array of an entity before rendering to HTML.
 *
 * This hook allows other modules to modify the render array of an entity
 * before it is rendered to HTML during Markdown transformation.
 *
 * @param array &$build
 *   The render array for the entity.
 * @param array $context
 *   An associative array containing additional context:
 *   - entity: The entity being rendered (\Drupal\Core\Entity\EntityInterface).
 *   - view_mode: The view mode used to render the entity (string).
 *   - langcode: The language code for rendering (string).
 * @param \Drupal\Core\Render\BubbleableMetadata|null $metadata
 *   (optional) Cacheable metadata for the rendering process.
 */
function hook_markdownify_entity_build_alter(array &$build, array $context, ?BubbleableMetadata $metadata): void {
  // Example: Add a custom class to all entities of type 'node'.
  $entity = $context['entity'];
  // Example: Add metadata to cache tags for 'article' node bundles.
  if ($entity->getEntityTypeId() === 'node' && $entity->bundle() === 'article' && $metadata) {
    $metadata->addCacheTags(['custom_article_tag']);
  }
}

/**
 * Alter the rendered HTML of an entity before conversion to Markdown.
 *
 * This hook allows other modules to modify the HTML output of an entity
 * before it is passed to the Markdown converter.
 *
 * @param string &$html
 *   The rendered HTML of the entity.
 * @param array $context
 *   An associative array containing additional context:
 *   - entity: The entity being converted (\Drupal\Core\Entity\EntityInterface).
 *   - view_mode: The view mode used to render the entity (string).
 *   - langcode: The language code of the entity's content (string).
 * @param \Drupal\Core\Render\BubbleableMetadata|null $metadata
 *   (optional) Cacheable metadata for the rendering process.
 */
function hook_markdownify_entity_html_alter(string &$html, array $context, ?BubbleableMetadata $metadata): void {
  // Example: Add a custom wrapper around the HTML.
  $html = '<div class="custom-wrapper">' . $html . '</div>';
  // Example: Modify the HTML for a specific entity type.
  $entity = $context['entity'];
  if ($entity->getEntityTypeId() === 'node' && $entity->bundle() === 'article') {
    $html .= '<!-- Custom footer for article nodes -->';
  }
}

/**
 * Alter the Markdown output of an entity after conversion from HTML.
 *
 * This hook allows other modules to modify the Markdown output of an entity
 * after it has been converted from HTML.
 *
 * @param string &$markdown
 *   The Markdown output of the entity.
 * @param array $context
 *   An associative array containing additional context:
 *   - html: The original rendered HTML of the entity.
 */
function hook_markdownify_entity_markdown_alter(string &$markdown, array $context): void {
  // Example: Prepend a custom header to the Markdown output.
  if (strpos($context['html'], '<h1>') !== FALSE) {
    $markdown = "# Custom Header\n\n" . $markdown;
  }
}
