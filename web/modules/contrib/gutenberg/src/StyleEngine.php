<?php

Namespace Drupal\gutenberg;

require_once __DIR__ . '/StyleEngine/utils.php';
require_once __DIR__ . '/StyleEngine/style-engine-gutenberg.php';
require_once __DIR__ . '/StyleEngine/class-wp-style-engine-gutenberg.php';
require_once __DIR__ . '/StyleEngine/class-wp-style-engine-css-declarations-gutenberg.php';
require_once __DIR__ . '/StyleEngine/class-wp-style-engine-css-rule-gutenberg.php';
require_once __DIR__ . '/StyleEngine/class-wp-style-engine-css-rules-store-gutenberg.php';
require_once __DIR__ . '/StyleEngine/class-wp-style-engine-processor-gutenberg.php';

class StyleEngine {
  public static function gutenberg_style_engine_get_styles($block_styles, $options = array()) {
    return gutenberg_style_engine_get_styles($block_styles, $options = array());
  }

  public static function gutenberg_style_engine_get_stylesheet_from_css_rules( $css_rules, $options = array() ) {
    return gutenberg_style_engine_get_stylesheet_from_css_rules($css_rules, $options);
  }

  public static function gutenberg_style_engine_get_stylesheet_from_context( $context, $options = array() ) {
    return gutenberg_style_engine_get_stylesheet_from_context($context, $options);
  }

  public static function get_stores() {
    return \WP_Style_Engine_CSS_Rules_Store_Gutenberg::get_stores();
  }
}