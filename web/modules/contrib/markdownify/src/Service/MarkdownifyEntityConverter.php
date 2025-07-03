<?php

namespace Drupal\markdownify\Service;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\markdownify\MarkdownifyEntityConverterInterface;
use Drupal\markdownify\MarkdownifyEntityRendererInterface;
use Drupal\markdownify\MarkdownifyHtmlConverterInterface;

/**
 * Service for converting entities to Markdown format.
 *
 * This service handles the process of rendering an entity as HTML and then
 * converting the resulting HTML to Markdown format.
 *
 * It uses the MarkdownifyEntityRenderer service to generate HTML and the
 * MarkdownifyHtmlConverter service to perform the HTML-to-Markdown conversion.
 *
 * @see \Drupal\markdownify\Service\MarkdownifyEntityRenderer::toHtml()
 * @see \Drupal\markdownify\Service\MarkdownifyHtmlConverter::convert()
 */
class MarkdownifyEntityConverter implements MarkdownifyEntityConverterInterface {

  /**
   * The Markdownify Entity Renderer service.
   *
   * @var \Drupal\markdownify\MarkdownifyEntityRendererInterface
   */
  protected MarkdownifyEntityRendererInterface $renderer;

  /**
   * The Markdownify HTML to Markdown Converter service.
   *
   * @var \Drupal\markdownify\\MarkdownifyHtmlConverterInterface
   */
  protected MarkdownifyHtmlConverterInterface $converter;

  /**
   * Constructs a new MarkdownifyEntityConverter object.
   *
   * @param \Drupal\markdownify\MarkdownifyEntityRendererInterface $renderer
   *   The service for rendering entities as HTML.
   * @param \Drupal\markdownify\MarkdownifyHtmlConverterInterface $converter
   *   The service for converting HTML to Markdown.
   */
  public function __construct(MarkdownifyEntityRendererInterface $renderer, MarkdownifyHtmlConverterInterface $converter) {
    $this->renderer = $renderer;
    $this->converter = $converter;
  }

  /**
   * {@inheritdoc}
   */
  public function convertEntityToMarkdown(EntityInterface $entity, string $view_mode = 'full', ?string $langcode = NULL, ?BubbleableMetadata $metadata = NULL): string {
    // Render the entity as HTML.
    $html = $this->renderer->toHtml($entity, $view_mode, $langcode, $metadata);
    // Convert the rendered HTML to Markdown.
    return $this->converter->convert($html, $metadata);
  }

}
