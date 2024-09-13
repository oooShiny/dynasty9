<?php

namespace Drupal\Tests\gutenberg\FunctionalJavascript;

/**
 * Test enable the media block update hook.
 *
 * @group gutenberg
 */
class UpdateAddMediaTest extends GutenbergWebdriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'media',
  ];

  /**
   * Test the function gutenberg_update_8206.
   */
  public function testMediaUpdate() {
    // Cleanly installed site, no media enabled then (it has to be explicitly
    // enabled).
    $this->assertBlockIsNotEnabled('Media');
    $module_handler = $this->container->get('module_handler');
    $module_handler->loadInclude('gutenberg', 'install');
    gutenberg_update_8206();
    // The update hook of 8301 changed around some of the config for
    // gutenberg.settings, so simply just running the update hook of 8206 will
    // make the settings be in the wrong format. Therefore we also run the
    // 8301 update hook here.
    gutenberg_update_8301();
    // Before this update hook, everyone with the media module installed would
    // get the media block enabled. So we want to make sure it's still always
    // there after it is being applied.
    $this->assertBlockIsEnabled('Media');
  }

}
