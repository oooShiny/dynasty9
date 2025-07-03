<?php

namespace Drupal\markdownify;

use Drupal\Component\Plugin\FallbackPluginManagerInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\markdownify\Attribute\HtmlToMarkdownConverter;

/**
 * Provides the html to markdown converter plugin manager.
 */
class HtmlToMarkdownConverterManager extends DefaultPluginManager implements FallbackPluginManagerInterface {

  /**
   * Constructs a HtmlToMarkdownConverterManager object.
   *
   * @param \Traversable $namespaces
   *   An object that implements \Traversable which contains the root paths
   *   keyed by the corresponding namespace to look for plugin implementations.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   Cache backend instance to use.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler to invoke the alter hook with.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/HtmlToMarkdownConverter', $namespaces, $module_handler, MarkdownifyHtmlConverterInterface::class, HtmlToMarkdownConverter::class);
    $this->setCacheBackend($cache_backend, 'markdownify_converter_plugins');
    $this->alterInfo('html_to_markdown_converter_info');
  }

  /**
   * {@inheritdoc}
   */
  public function getFallbackPluginId($plugin_id, array $configuration = []) {
    return 'league';
  }

}
