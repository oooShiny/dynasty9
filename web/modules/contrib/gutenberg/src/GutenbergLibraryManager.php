<?php

namespace Drupal\gutenberg;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Discovery\YamlDiscovery;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\Core\Theme\MissingThemeDependencyException;
use Drupal\Core\Theme\ThemeInitializationInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Provides the default .gutenberg.yml library plugin manager.
 */
class GutenbergLibraryManager extends DefaultPluginManager implements GutenbergLibraryManagerInterface {

  /**
   * Provides default values for all gutenberg plugins.
   *
   * @var array
   */
  protected $defaults = [
    'libraries-edit' => [],
    'libraries-view' => [],
    'dynamic-blocks' => [],
    'custom-blocks' => []
  ];

  /**
   * The theme handler.
   *
   * @var \Drupal\Core\Extension\ThemeHandlerInterface
   */
  protected $themeHandler;

  /**
   * The theme initialization.
   *
   * @var \Drupal\Core\Theme\ThemeInitializationInterface
   */
  protected $themeInitialization;

  /**
   * The Gutenberg logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Static cache of theme Gutenberg plugin definitions.
   *
   * @var array
   */
  protected $activeThemeDefinitions;

  /**
   * Static cache of the merged active theme Gutenberg plugin definition.
   *
   * @var array
   */
  protected $activeThemeMergedDefinition;

  /**
   * Static cache of Gutenberg plugin definitions keyed by the extension type.
   *
   * @var array
   */
  protected $definitionsByExtension;

  /* @noinspection MagicMethodsValidityInspection */
  /* @noinspection PhpMissingParentConstructorInspection */

  /**
   * Constructs a new GutenbergManager object.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\Extension\ThemeHandlerInterface $theme_handler
   *   The theme handler.
   * @param \Drupal\Core\Theme\ThemeInitializationInterface $theme_initialization
   *   The theme initialization.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   Cache backend instance to use.
   * @param \Psr\Log\LoggerInterface $logger
   *   Gutenberg logger interface.
   */
  public function __construct(
    ModuleHandlerInterface $module_handler,
    ThemeHandlerInterface $theme_handler,
    ThemeInitializationInterface $theme_initialization,
    CacheBackendInterface $cache_backend,
    LoggerInterface $logger
  ) {
    $this->moduleHandler = $module_handler;
    $this->themeHandler = $theme_handler;
    $this->themeInitialization = $theme_initialization;
    $this->logger = $logger;
    $this->alterInfo('gutenberg_info');
    $this->setCacheBackend($cache_backend, 'gutenberg', ['gutenberg']);
  }

  /**
   * {@inheritdoc}
   */
  protected function getDiscovery() {
    if (!isset($this->discovery)) {
      $this->discovery = new YamlDiscovery(
        'gutenberg',
        $this->moduleHandler->getModuleDirectories() + $this->themeHandler->getThemeDirectories()
      );
    }
    return $this->discovery;
  }

  /**
   * {@inheritdoc}
   */
  protected function findDefinitions() {
    $definitions = $this->getDiscovery()->findAll();

    foreach ($definitions as $plugin_id => &$definition) {
      $definitions[$plugin_id] = $definition + [
        'id' => $plugin_id,
        'provider' => $plugin_id,
      ];
      $this->processDefinition($definition, $plugin_id);
    }
    unset($definition);

    $this->alterDefinitions($definitions);

    // If this plugin was provided by a module/theme that does not exist,
    // remove the plugin definition.
    foreach ($definitions as $plugin_id => $definition) {
      $plugin_id = $this->extractProviderFromDefinition($definition);
      if ($plugin_id && !in_array($plugin_id, ['core', 'component']) && !$this->providerExists($plugin_id)) {
        unset($definitions[$plugin_id]);
      }
    }
    return $definitions;
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   */
  public function processDefinition(&$definition, $plugin_id) {
    parent::processDefinition($definition, $plugin_id);

    if (empty($definition['provider'])) {
      throw new InvalidPluginDefinitionException(
        sprintf('Gutenberg plugin property (%s) definition "provider" is required.', $plugin_id)
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function providerExists($provider) {
    return $this->moduleHandler->moduleExists($provider) || $this->themeHandler->themeExists($provider);
  }

  /**
   * {@inheritdoc}
   */
  public function clearCachedDefinitions() {
    parent::clearCachedDefinitions();
    $this->definitionsByExtension = NULL;
    $this->activeThemeDefinitions = NULL;
    $this->activeThemeMergedDefinition = NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getModuleDefinitions() {
    return $this->getDefinitionsByExtension()['module'];
  }

  /**
   * {@inheritdoc}
   */
  public function getThemeDefinitions() {
    return $this->getDefinitionsByExtension()['theme'];
  }

  /**
   * {@inheritdoc}
   */
  public function getActiveThemeDefinitions() {
    if (isset($this->activeThemeDefinitions)) {
      return $this->activeThemeDefinitions;
    }

    $cid = $this->cacheKey . ':active_themes';

    if ($cache = $this->cacheBackend->get($cid)) {
      return $this->activeThemeDefinitions = $cache->data;
    }

    $theme_name = $this->themeHandler->getDefault();
    $theme_definitions = [];
    try {
      $active_theme = $this->themeInitialization->getActiveThemeByName($theme_name);
      $definitions = $this->getDefinitionsByExtension();
      $default_theme_definitions = [];

      // Check if Gutenberg module has "default" settings for the active theme.
      // TODO: A better way to do this?
      $module_path = $this->moduleHandler->getModule('gutenberg')->getPath();
      $config_file_path = $module_path . '/' . $theme_name . '.gutenberg.yml';

      if (file_exists($config_file_path)) {
        $default_theme_definitions = Yaml::parseFile($config_file_path);
      }

      // Note: Reversing the order so that base themes are first.
      $themes = array_reverse(
        array_merge([$active_theme->getName()], array_keys($active_theme->getBaseThemeExtensions()))
      );

      foreach ($themes as $theme) {
        if (isset($definitions['theme'][$theme])) {
          $theme_definitions[$theme] = array_merge($default_theme_definitions, $definitions['theme'][$theme]);
        }
        elseif (!empty($default_theme_definitions)) {
          $theme_definitions[$theme] = $default_theme_definitions;
        }
      }
    }
    catch (MissingThemeDependencyException $e) {
      $this->logger->error($e->getMessage());
    }

    // Process style section on each theme definition.
    foreach ($theme_definitions as $theme_name => &$theme_definition) {
      if (!empty($theme_definition['theme-support']['styles'])) {
        $this->processThemeSupportStyles($theme_definition, $active_theme);
      }
    }

    $this->cacheBackend->set(
      $cid,
      $theme_definitions,
      Cache::PERMANENT,
      ['gutenberg']
    );
    $this->activeThemeDefinitions = $theme_definitions;
    
    return $this->activeThemeDefinitions;
  }

  /**
   * {@inheritdoc}
   */
  public function getActiveThemeMergedDefinition() {
    if (isset($this->activeThemeMergedDefinition)) {
      return $this->activeThemeMergedDefinition;
    }

    $cid = $this->cacheKey . ':active_theme';

    if ($cache = $this->cacheBackend->get($cid)) {
      return $this->activeThemeMergedDefinition = $cache->data;
    }

    // Specify the default definition.
    $definition = $this->defaults;

    foreach ($this->getActiveThemeDefinitions() as $array) {
      foreach ($array as $key => $value) {
        if ($key === 'id' || $key === 'provider') {
          // Ignore irrelevant properties.
          continue;
        }

        if (isset($definition[$key]) && is_array($definition[$key]) && is_array($value)) {
          // Merge arrays.
          $definition[$key] = array_merge($definition[$key], $value);
        }
        // Otherwise, use the latter value, overriding any previous value.
        else {
          $definition[$key] = $value;
        }
      }
    }

    $this->activeThemeMergedDefinition = $definition;
    $this->cacheBackend->set($cid, $definition, Cache::PERMANENT, ['gutenberg']);

    return $this->activeThemeMergedDefinition;
  }

  /**
   * {@inheritdoc}
   */
  public function getDefinitionsByExtension() {
    if (isset($this->definitionsByExtension)) {
      return $this->definitionsByExtension;
    }

    $cid = $this->cacheKey . ':by_extension';

    if ($cache = $this->cacheBackend->get($cid)) {
      return $this->definitionsByExtension = $cache->data;
    }

    $definitions_by_extension = [
      'theme' => [],
      'module' => [],
    ];
    foreach ($this->getDefinitions() as $plugin_id => $plugin_definition) {
      if ($this->themeHandler->themeExists($plugin_id)) {
        $definitions_by_extension['theme'][$plugin_id] = $plugin_definition;
      }
      elseif ($this->moduleHandler->moduleExists($plugin_id)) {
        $definitions_by_extension['module'][$plugin_id] = $plugin_definition;
      }
    }

    $this->cacheBackend->set($cid, $definitions_by_extension, Cache::PERMANENT, ['gutenberg']);
    $this->definitionsByExtension = $definitions_by_extension;

    return $this->definitionsByExtension;
  }

  /**
   * Generate color css classes.
   * 
   * @return string|bool
   */
  public function generateThemeColorClasses() {
    $definitions = $this->getActiveThemeMergedDefinition();

    $disableGeneratedColorCssClasses =
      isset($definitions['theme-support']['disableGeneratedColorCssClasses'])
      && (
        !empty($definitions['theme-support']['disableGeneratedColorCssClasses'])
        || $definitions['theme-support']['disableGeneratedColorCssClasses']
      );

    // @todo: To deprecate.
    $themeIncludesColors =
      isset($definitions['theme-includes-colors'])
      && !empty($definitions['theme-includes-colors']);

    if ($disableGeneratedColorCssClasses && !$themeIncludesColors) {
      return FALSE;
    }

    $themeColors = [];

    if (!empty($definitions['theme-support']['colors'])) {
      $themeColors = $definitions['theme-support']['colors'];
    }

    if (!empty($definitions['theme-support']['__experimentalFeatures']['color']['palette']['default'])) {
      $themeColors = array_merge($themeColors, $definitions['theme-support']['__experimentalFeatures']['color']['palette']['default']);
    }

    if (!empty($definitions['theme-support']['__experimentalFeatures']['color']['palette']['theme'])) {
      $themeColors = array_merge($themeColors, $definitions['theme-support']['__experimentalFeatures']['color']['palette']['theme']);
    }

    $css_markup = '';

    // Generate color variables.
    foreach ($themeColors as $color) {
      $color_name = $color['slug'];
      $color_value = $color['color'];
      $css_markup .= "  .has-{$color_name}-color{ color:{$color_value} }";
      $css_markup .= "  .has-{$color_name}-background-color{ background-color:{$color_value} }";
      $css_markup .= "  .has-{$color_name}-border-color{ border-color:{$color_value} }";
    }

    if (empty($css_markup)) {
      return FALSE;
    }

    return ":root { $css_markup }";
  }

  /**
   * Generate gradient css classes.
   * 
   * @return string|bool
   */
  public function generateThemeGradientClasses() {
    $definitions = $this->getActiveThemeMergedDefinition();

    $disableGeneratedGradientCssClasses =
      isset($definitions['theme-support']['disableGeneratedGradientCssClasses'])
      && (
        !empty($definitions['theme-support']['disableGeneratedGradientCssClasses'])
        || $definitions['theme-support']['disableGeneratedGradientCssClasses']
      );

    if ($disableGeneratedGradientCssClasses) {
      return FALSE;
    }

    $themeGradients = [];

    // @todo: To deprecate.
    if (!empty($definitions['theme-support']['gradients'])) {
      $themeGradients = $definitions['theme-support']['gradients'];
    }

    if (!empty($definitions['theme-support']['__experimentalFeatures']['color']['gradients']['default'])) {
      $themeGradients = array_merge($themeGradients, $definitions['theme-support']['__experimentalFeatures']['color']['gradients']['default']);
    }

    if (!empty($definitions['theme-support']['__experimentalFeatures']['color']['gradients']['theme'])) {
      $themeGradients = array_merge($themeGradients, $definitions['theme-support']['__experimentalFeatures']['color']['gradients']['theme']);
    }

    $css_markup = '';

    // Generate color variables.
    foreach ($themeGradients as $gradient) {
      $gradient_name = $gradient['slug'];
      $gradient_value = $gradient['gradient'];
      $css_markup .= "  .has-{$gradient_name}-gradient-background{ background:{$gradient_value} }";
    }

    if (empty($css_markup)) {
      return FALSE;
    }

    return ":root { $css_markup }";
  }

  /**
   * Generate font size css classes.
   * 
   * @return string|bool
   */
  public function generateThemeFontSizeClasses() {
    $definitions = $this->getActiveThemeMergedDefinition();

    $disableGeneratedFontSizeCssClasses =
      isset($definitions['theme-support']['disableGeneratedFontSizeCssClasses'])
      && (
        !empty($definitions['theme-support']['disableGeneratedFontSizeCssClasses'])
        || $definitions['theme-support']['disableGeneratedFontSizeCssClasses']
      );

    if ($disableGeneratedFontSizeCssClasses) {
      return FALSE;
    }

    $themeFontSizes = [];

    // @todo: To deprecate.
    if (!empty($definitions['theme-support']['fontSizes'])) {
      $themeFontSizes = $definitions['theme-support']['fontSizes'];
    }

    if (!empty($definitions['theme-support']['__experimentalFeatures']['typography']['fontSizes']['default'])) {
      $themeFontSizes = array_merge($themeFontSizes, $definitions['theme-support']['__experimentalFeatures']['typography']['fontSizes']['default']);
    }

    if (!empty($definitions['theme-support']['__experimentalFeatures']['typography']['fontSizes']['theme'])) {
      $themeFontSizes = array_merge($themeFontSizes, $definitions['theme-support']['__experimentalFeatures']['typography']['fontSizes']['theme']);
    }

    $css_markup = '';

    // Generate color variables.
    foreach ($themeFontSizes as $fontSize) {
      $size_name = $fontSize['slug'];
      $size_value = $fontSize['size'];
      $css_markup .= "  .has-{$size_name}-font-size{ font-size:{$size_value} }";
    }

    if (empty($css_markup)) {
      return FALSE;
    }

    return ":root { $css_markup }";
  }

  /**
   * Process theme support styles.
   * It checks for files in styles sections and loads them.
   * 
   * @param array $theme_definition
   *  The theme definition.
   * @param \Drupal\Core\Theme\ActiveTheme $active_theme
   * The active theme.
   * 
   */
  protected function processThemeSupportStyles(&$theme_definition, $active_theme) {
    $path = $active_theme->getPath();
    foreach ($theme_definition['theme-support']['styles'] as $key => &$style) {
      if ($style['css'] && is_array($style['css'])) {
        $resultCss = '';
        foreach ($style['css'] as $filename => $file_settings) {
          $style_file_path = \Drupal::root() . '/' . $path . '/' . $filename;
          if (file_exists($style_file_path)) {
            $css = file_get_contents($style_file_path);

            // Get folder path from file path.
            $folder_path = dirname($path . '/' . $filename);

            // Process urls.
            $re = '/url\((?![\'"]?(?:data):)[\'"]?([^\'"\)]*)[\'"]?\)/m';
            $subst = "url(\"/" . $folder_path . "/$1\")";            
            $resultCss .= preg_replace($re, $subst, $css) . "\n";
          }
        }
        $style['css'] = $resultCss;
      }
    }
  }

  /**
   * Generate spacing sizes CSS variables.
   */
  public function generateSpacingSizesCssVariables() {
    $definitions = $this->getActiveThemeMergedDefinition();

    $disableGeneratedSpacingSizesCssVariables =
      isset($definitions['theme-support']['disableGeneratedSpacingSizesCssVariables'])
      && (
        !empty($definitions['theme-support']['disableGeneratedSpacingSizesCssVariables'])
        || $definitions['theme-support']['disableGeneratedSpacingSizesCssVariables']
      );

    if ($disableGeneratedSpacingSizesCssVariables) {
      return FALSE;
    }

    $themeSpacingSizes = [];

    if (empty($definitions['theme-support']['__experimentalFeatures']['spacing'])) {
      return FALSE;
    }

    if (!empty($definitions['theme-support']['__experimentalFeatures']['spacing']['spacingSizes'])) {
      $themeSpacingSizes = $definitions['theme-support']['__experimentalFeatures']['spacing']['spacingSizes']['theme'];
    }

    $css_markup = '';

    // Generate color variables.
    foreach ($themeSpacingSizes as $size) {
      $size_name = $size['slug'];
      $size_value = $size['size'];
      $css_markup .= "  --wp--preset--spacing--{$size_name}:{$size_value};";
    }

    if (empty($css_markup)) {
      return FALSE;
    }

    return ":root { $css_markup }";
  }
}
