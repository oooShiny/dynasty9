<?php

namespace Drupal\markdownify\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\markdownify\MarkdownifySupportedEntityTypesValidatorInterface;

/**
 * Manages and validates supported entity types for Markdownify.
 *
 * This service interacts with the module configuration to determine the list
 * of entity types that Markdownify supports. It also allows other modules to
 * alter the list through hook implementations.
 */
class MarkdownifySupportedEntityTypesValidator implements MarkdownifySupportedEntityTypesValidatorInterface {

  /**
   * The configuration factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * Constructs a new MarkdownifySupportedEntityTypes object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler service.
   */
  public function __construct(ConfigFactoryInterface $config_factory, ModuleHandlerInterface $module_handler) {
    $this->configFactory = $config_factory;
    $this->moduleHandler = $module_handler;
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedEntityTypes(): array {
    // Load the Markdownify configuration settings.
    $config = $this->configFactory->get('markdownify.settings');
    // Get the supported entity types from the configuration.
    $supported_entity_types = $config->get('supported_entity_types') ?? [];
    // Allow other modules to alter the supported entity types list.
    $this->moduleHandler->alter('markdownify_supported_entity_types', $supported_entity_types);
    // Return the list of supported entity types.
    return $supported_entity_types;
  }

  /**
   * {@inheritdoc}
   */
  public function isSupported(string $entity_type): bool {
    return in_array($entity_type, $this->getSupportedEntityTypes(), TRUE);
  }

}
