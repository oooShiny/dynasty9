<?php

namespace Drupal\Tests\gutenberg\FunctionalJavascript;

/**
 * Test that the editor works in different admin themes.
 */
class AdminThemeTest extends GutenbergWebdriverTestBase {

  /**
   * Test that the editor works in different themes.
   *
   * @dataProvider adminThemeDataProvider
   */
  public function testAdminTheme($theme) {
    // Ensure the default theme is installed.
    $this->container->get('theme_installer')->install([$theme], TRUE);

    $keys_to_set = [
      'default',
      'admin',
    ];
    foreach ($keys_to_set as $key) {
      $config = $this->container->get('config.factory')->getEditable('system.theme');
      $config->set($key, $theme)->save();
    }
    $node_settings = $this->container->get('config.factory')->getEditable('node.settings');
    $node_settings->set('use_admin_theme', TRUE)->save();
    // Just invoke this finding of a block, since that will indicate the editor
    // is working.
    $this->assertBlockIsEnabled('Paragraph');
  }

  /**
   * Data provider for testAdminTheme.
   */
  public function adminThemeDataProvider() {
    return [
      ['claro'],
      ['stark'],
      ['olivero'],
      ['test_super_empty_theme']
    ];
  }

}
