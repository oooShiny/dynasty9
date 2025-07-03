<?php

namespace Drupal\markdownify\Service;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\markdownify\MarkdownifyEntityRendererInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for rendering Drupal entities as HTML.
 *
 * This service provides methods to render a given entity as an HTML string,
 * ensuring that it integrates with Drupal's render pipeline, cacheable
 * metadata, and module hooks for altering the rendered output.
 */
class MarkdownifyEntityRenderer implements MarkdownifyEntityRendererInterface {

  /**
   * The entity type manager service.
   *
   * Used to load the appropriate view builder for rendering entities.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected RendererInterface $renderer;

  /**
   * The module handler service.
   *
   * Provides hooks for other modules to alter the rendered output.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructs a new MarkdownifyEntityRenderer object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, RendererInterface $renderer, ModuleHandlerInterface $module_handler, LoggerInterface $logger) {
    $this->entityTypeManager = $entity_type_manager;
    $this->renderer = $renderer;
    $this->moduleHandler = $module_handler;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public function toHtml(EntityInterface $entity, string $view_mode = 'full', ?string $langcode = NULL, ?BubbleableMetadata $metadata = NULL): string {
    try {
      // Set the render context to capture cacheable metadata.
      $context = new RenderContext();
      // Render entity in context and collect cacheable metadata.
      $html = $this->renderer->executeInRenderContext($context, function () use ($entity, $view_mode, $langcode, $metadata) {
        return $this->renderEntity($entity, $view_mode, $langcode, $metadata);
      });
      // Merge any bubbled cacheable metadata.
      $this->applyCacheableMetadata($entity, $context, $metadata);
      // Returns the fully rendered HTML output.
      return $html;
    }
    catch (\Exception $e) {
      // Log any exceptions that occur during rendering.
      $this->logger->error('Failed to render entity: @message', ['@message' => $e->getMessage()]);
      return '';
    }
  }

  /**
   * Builds and renders the entity view array into an HTML string.
   *
   * This method integrates with Drupal's rendering pipeline, invokes alter
   * hooks for both render arrays and HTML strings, and ensures proper
   * cache metadata handling.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to render.
   * @param string $view_mode
   *   The view mode for rendering the entity.
   * @param string|null $langcode
   *   (optional) The language code for rendering.
   * @param \Drupal\Core\Render\BubbleableMetadata|null $metadata
   *   (optional) Cacheable metadata for the rendering process.
   *
   * @return string
   *   The rendered HTML output.
   */
  protected function renderEntity(EntityInterface $entity, string $view_mode, ?string $langcode, ?BubbleableMetadata $metadata = NULL): string {
    // Context for hook alterations.
    $alter_context = [
      'entity' => $entity,
      'view_mode' => $view_mode,
      'langcode' => $langcode,
    ];
    // Obtain the appropriate view builder for the entity type.
    $view_builder = $this->entityTypeManager->getViewBuilder($entity->getEntityTypeId());
    // Build the render array for the entity.
    $build = $view_builder->view($entity, $view_mode, $langcode);
    // Allow modules to alter the render array before rendering as HTML.
    $this->moduleHandler->alter('markdownify_entity_build', $build, $alter_context, $metadata);
    // Render the build array into an HTML string.
    $html = $this->renderer->render($build, TRUE);
    // Allow modules to alter the rendered HTML output.
    $this->moduleHandler->alter('markdownify_entity_html', $html, $alter_context, $metadata);
    // Adds cacheable metadata to ensure proper caching of the rendered output.
    if ($metadata instanceof BubbleableMetadata) {
      $metadata->addCacheableDependency($build);
    }
    // Returns the fully rendered HTML output.
    return $html;
  }

  /**
   * Applies cache metadata from the render context to ensure proper caching.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being rendered.
   * @param \Drupal\Core\Render\RenderContext $context
   *   The render context containing cache metadata.
   * @param \Drupal\Core\Render\BubbleableMetadata|null $metadata
   *   The cacheable metadata object.
   */
  protected function applyCacheableMetadata(EntityInterface $entity, RenderContext $context, ?BubbleableMetadata $metadata): void {
    if (!$metadata instanceof BubbleableMetadata) {
      return;
    }
    // Add the given entity as a cacheable dependency.
    $metadata->addCacheableDependency($entity);
    // Merge cacheable metadata from render context.
    if (!$context->isEmpty()) {
      $metadata->merge($context->pop());
    }
    // Also associate the "rendered" cache tag. This allows us to invalidate the
    // entire render cache, regardless of the cache bin.
    $metadata->addCacheTags(['rendered']);
  }

}
