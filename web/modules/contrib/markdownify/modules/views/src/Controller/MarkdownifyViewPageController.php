<?php

namespace Drupal\markdownify_views\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\markdownify\MarkdownifyHtmlConverterInterface;
use Drupal\markdownify\MarkdownResponse;
use Drupal\views\Routing\ViewPageController;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Handles requests to generate Markdown versions of Drupal Views pages.
 *
 * This controller:
 * - Retrieves and renders a Views page as HTML.
 * - Converts the HTML into Markdown format using the Markdownify service.
 * - Returns a Markdown response while maintaining proper cache metadata.
 */
class MarkdownifyViewPageController extends ViewPageController implements ContainerInjectionInterface {

  /**
   * The service responsible for rendering views as HTML.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected RendererInterface $renderer;

  /**
   * The service for converting HTML to Markdown.
   *
   * @var \Drupal\markdownify\MarkdownifyHtmlConverterInterface
   */
  protected MarkdownifyHtmlConverterInterface $converter;

  /**
   * Constructs a new MarkdownifyViewPageController object.
   *
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The service for rendering views as HTML.
   * @param \Drupal\markdownify\MarkdownifyHtmlConverterInterface $converter
   *   The service for converting HTML to Markdown.
   */
  public function __construct(RendererInterface $renderer, MarkdownifyHtmlConverterInterface $converter) {
    $this->renderer = $renderer;
    $this->converter = $converter;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('renderer'),
      $container->get('markdownify.html_converter')
    );
  }

  /**
   * Generates and returns a Markdown response for a given view.
   *
   * @param string $view_id
   *   The machine name of the view.
   * @param string $display_id
   *   The ID of the display.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match object.
   *
   * @return \Drupal\markdownify\MarkdownResponse
   *   The response containing the converted Markdown output.
   */
  public function handle($view_id, $display_id, RouteMatchInterface $route_match) {
    // Generate the view's render array, with caching considerations.
    $build = parent::handle($view_id, $display_id, $route_match);
    // Initialize cacheable metadata.
    $metadata = $this->initializeCacheMetadata();
    // Render the view as HTML and collect cache metadata.
    $html = $this->renderBuild($build, $metadata);
    // Convert the rendered HTML to Markdown.
    $markdown = $this->convertHtmlToMarkdown($html, $metadata);
    // Prepend View page title to the markdown.
    $title = $this->getTitle($view_id, $display_id);
    if (!empty($title)) {
      $markdown = "# {$title}\n\n" . $markdown;
    }
    // Return the response with proper caching dependencies.
    return $this->createMarkdownResponse($markdown, $metadata);
  }

  /**
   * Renders a given render array into HTML while collecting cache metadata.
   *
   * @param array $build
   *   The render array.
   * @param \Drupal\Core\Render\BubbleableMetadata $metadata
   *   The cache metadata object (updated during rendering).
   *
   * @return string
   *   The fully rendered HTML output.
   */
  protected function renderBuild(array $build, BubbleableMetadata $metadata): string {
    // Set up a render context to track cacheable metadata.
    $context = new RenderContext();
    // Render the build array while capturing cache metadata.
    $html = $this->renderer->executeInRenderContext($context, function () use ($build) {
      return $this->renderer->render($build);
    });
    // Merge cache metadata from the render context.
    if (!$context->isEmpty()) {
      $metadata->merge($context->pop());
    }
    // Also associate the "rendered" cache tag. This allows us to invalidate the
    // entire render cache, regardless of the cache bin.
    $metadata->addCacheTags(['rendered']);
    // Return the rendered output.
    return $html;
  }

  /**
   * Initializes cache metadata for the response.
   *
   * @return \Drupal\Core\Render\BubbleableMetadata
   *   The cache metadata object with initial settings.
   */
  protected function initializeCacheMetadata(): BubbleableMetadata {
    $metadata = new BubbleableMetadata();
    $metadata->addCacheContexts(['languages', 'url.query_args:_format']);
    return $metadata;
  }

  /**
   * Converts a given HTML string into Markdown format.
   *
   * @param string $html
   *   The HTML content.
   * @param \Drupal\Core\Render\BubbleableMetadata $metadata
   *   The cache metadata object (updated if needed).
   *
   * @return string
   *   The Markdown-formatted string.
   */
  protected function convertHtmlToMarkdown(string $html, BubbleableMetadata $metadata): string {
    return $this->converter->convert($html, $metadata);
  }

  /**
   * Creates a Markdown response while preserving cacheable metadata.
   *
   * @param string $markdown
   *   The generated Markdown content.
   * @param \Drupal\Core\Render\BubbleableMetadata $metadata
   *   The associated cache metadata.
   *
   * @return \Drupal\markdownify\MarkdownResponse
   *   The response containing the Markdown output.
   */
  protected function createMarkdownResponse(string $markdown, BubbleableMetadata $metadata): MarkdownResponse {
    $response = new MarkdownResponse($markdown, 200, []);
    $response->addCacheableDependency($metadata);
    return $response;
  }

}
