<?php

namespace Drupal\markdownify;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceModifierInterface;
use Drupal\Core\StackMiddleware\NegotiationMiddleware;

/**
 * Adds 'text/markdown' as a known (markdown) format.
 *
 * This service provider modifies the HTTP negotiation middleware to recognize
 * 'text/markdown' as a valid MIME type for the 'markdown' format, enabling
 * support for Markdown content type negotiation.
 *
 * @see https://www.iana.org/assignments/media-types/text/markdown
 * @see https://www.rfc-editor.org/rfc/rfc7763.html
 */
class MarkdownifyServiceProvider implements ServiceModifierInterface {

  /**
   * The Markdown format name and associated MIME types.
   *
   * @var array
   */
  protected array $format = ['markdown', ['text/markdown']];

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    // Check if the negotiation middleware service is valid before proceeding.
    if ($this->isNegotiationMiddlewareValid($container)) {
      // Register the 'markdown' format with the corresponding MIME type.
      $this->registerMarkdownFormat($container);
    }
  }

  /**
   * Checks if the negotiation middleware service is available and valid.
   *
   * This function ensures that the 'http_middleware.negotiation' service
   * exists in the container and that its class is compatible with
   * NegotiationMiddleware.
   *
   * @param \Drupal\Core\DependencyInjection\ContainerBuilder $container
   *   The service container.
   *
   * @return bool
   *   TRUE if the negotiation middleware service is valid, FALSE otherwise.
   */
  protected function isNegotiationMiddlewareValid(ContainerBuilder $container): bool {
    // Verify that the 'http_middleware.negotiation' service is defined.
    if (!$container->has('http_middleware.negotiation')) {
      return FALSE;
    }
    // Retrieve the service definition for the negotiation middleware.
    $definition = $container->getDefinition('http_middleware.negotiation');
    // Confirm the service's class is of type NegotiationMiddleware.
    return is_a($definition->getClass(), NegotiationMiddleware::class, TRUE);
  }

  /**
   * Registers the 'markdown' format with the MIME type 'text/markdown'.
   *
   * This function modifies the negotiation middleware service to include
   * support for the 'markdown' format by associating it with the MIME type
   * 'text/markdown'.
   *
   * @param \Drupal\Core\DependencyInjection\ContainerBuilder $container
   *   The service container.
   */
  protected function registerMarkdownFormat(ContainerBuilder $container): void {
    // Retrieve the service definition for the negotiation middleware.
    $definition = $container->getDefinition('http_middleware.negotiation');
    // Add 'markdown' format and associate it with 'text/markdown' MIME type.
    $definition->addMethodCall('registerFormat', $this->format);
  }

}
