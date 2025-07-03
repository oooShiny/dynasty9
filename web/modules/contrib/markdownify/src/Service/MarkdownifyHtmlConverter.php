<?php

namespace Drupal\markdownify\Service;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\markdownify\MarkdownifyHtmlConverterInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for converting HTML to Markdown.
 *
 * This service provides a mechanism to convert HTML content into Markdown
 * using the League HTML-to-Markdown library. The conversion process includes
 * options for stripping unsupported tags and handling line breaks.
 */
class MarkdownifyHtmlConverter implements MarkdownifyHtmlConverterInterface {

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
   * Used to log any errors or warnings during the conversion process.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * The html to markdown converter plugin manager.
   *
   * @var \Drupal\Component\Plugin\PluginManagerInterface
   */
  protected PluginManagerInterface $converterPluginManager;

  /**
   * The markdownify config settings.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected ImmutableConfig $config;

  /**
   * Constructs a new MarkdownifyHtmlConverter object.
   *
   * @param \Drupal\Component\Plugin\PluginManagerInterface $converter_plugin_manager
   *   The html to markdown converter plugin manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   */
  public function __construct(PluginManagerInterface $converter_plugin_manager, ConfigFactoryInterface $config_factory, ModuleHandlerInterface $module_handler, LoggerInterface $logger) {
    $this->converterPluginManager = $converter_plugin_manager;
    $this->config = $config_factory->get('markdownify.settings');
    $this->moduleHandler = $module_handler;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public function convert(string $html, ?BubbleableMetadata $metadata = NULL): string {
    if (empty($html)) {
      // Return an empty string if the input HTML is empty.
      return '';
    }
    try {
      $default_converter = $this->config->get('default_converter');
      $converter_configs = $this->config->get('converters');
      // Initialize the HTML-to-Markdown converter with specific options.
      $converter = $this->converterPluginManager->createInstance($default_converter, $converter_configs[$default_converter] ?? []);
      if ($metadata) {
        $metadata->addCacheableDependency($this->config);
      }
      // Convert the HTML to Markdown.
      $markdown = $converter->convert($html);
      // Allow other modules to alter the generated Markdown.
      $context = [
        'html' => $html,
        'metadata' => $metadata,
      ];
      $this->moduleHandler->alter('markdownify_entity_markdown', $markdown, $context);
      // Return the rendered output.
      return $markdown;
    }
    catch (\Exception $e) {
      // Log any exceptions that occur during the conversion process.
      $this->logger->error('Error converting HTML to Markdown: @message', ['@message' => $e->getMessage()]);
      return '';
    }
  }

}
