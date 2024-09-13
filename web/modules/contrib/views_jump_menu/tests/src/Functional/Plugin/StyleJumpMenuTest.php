<?php

namespace Drupal\Tests\views_jump_menu\Functional\Plugin;

use Drupal\Tests\views\Functional\ViewTestBase;

/**
 * Tests the Jump Menu style views plugin.
 *
 * @group views_jump_menu
 *
 * @see \Drupal\Tests\views\Functional\Plugin\StyleTableTest
 */
class StyleJumpMenuTest extends ViewTestBase {

  /**
   * Views used by this test.
   *
   * @var string[]
   */
  public static $testViews = ['test_style_jump_menu'];

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['views', 'views_jump_menu_test_config'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The name of a test user to look for in the jump menu options.
   *
   * @var string
   */
  protected $viewsJumpMenuTestUserName;

  /**
   * The canonical URL for a test user, as a string.
   *
   * @var \Drupal\Core\GeneratedUrl|string
   */
  protected $viewsJumpMenuTestUserUrlString;

  /**
   * {@inheritdoc}
   */
  protected function setUp($import_test_views = TRUE, $modules = []): void {
    parent::setUp($import_test_views);

    $this->enableViewsTestModule();

    // Add a user with a random name, and get a URL string for that user. Our
    // test view links to users' "view" page (i.e.: rel=canonical), and does not
    // use absolute links, so our URL string should do the same.
    $this->viewsJumpMenuTestUserName = $this->randomMachineName();
    $testUser = $this->drupalCreateUser([], $this->viewsJumpMenuTestUserName);
    $this->viewsJumpMenuTestUserUrlString = $testUser->toUrl('canonical', [
      'absolute' => FALSE,
    ])->toString();

    $this->drupalLogin($this->drupalCreateUser(['access user profiles']));
  }

  /**
   * Tests jump menu JavaScript library.
   */
  public function testJumpMenuLibrary() {
    $viewsJumpMenuModulePath = \Drupal::service('extension.list.module')->getPath('views_jump_menu');

    // Navigate to the test view.
    $this->drupalGet('test-style-jump-menu');

    // Check that the library was output onto the page.
    $this->assertSession()->responseContains($viewsJumpMenuModulePath . '/js/views-jump-menu.js');
  }

  /**
   * Tests jump menu output.
   */
  public function testJumpMenuOutput() {
    // Navigate to the test view.
    $this->drupalGet('test-style-jump-menu');

    // Check that the jump menu wrapper has the custom class configured in the
    // view.
    $wrapper = $this->xpath('//div[contains(concat(" ", @class, " "), " test-wrapper-class ")]');
    $this->assertGreaterThan(0, count($wrapper), 'Ensure there is a div with the class test-wrapper-class');

    // Check that the jump menu select list has the custom class configured in
    // the view.
    $select = $this->xpath('//select[contains(concat(" ", @class, " "), " test-select-class ")]');
    $this->assertGreaterThan(0, count($select), 'Ensure there is a select list with the class test-select-class');

    // Check that the jump menu select list has the ViewsJumpMenu class.
    $select = $this->xpath('//select[contains(concat(" ", @class, " "), " ViewsJumpMenu ")]');
    $this->assertGreaterThan(0, count($select), 'Ensure there is a select list with the class ViewsJumpMenu');

    // Check that the jump menu select list has the js-viewsJumpMenu class
    // required for the JavaScript to function.
    $select = $this->xpath('//select[contains(concat(" ", @class, " "), " js-viewsJumpMenu ")]');
    $this->assertGreaterThan(0, count($select), 'Ensure there is a select list with the class js-viewsJumpMenu');

    // Check that the jump menu select list has a title attribute which matches
    // the prompt option.
    $select = $this->xpath('//select[@title="-- Select --"]');
    $this->assertGreaterThan(0, count($select), 'Ensure there is a select list with the title -- Select --');

    // Check that the jump menu select list has the custom label configured in
    // the view.
    $select = $this->xpath('//select[@aria-label="test-select-label"]');
    $this->assertGreaterThan(0, count($select), 'Ensure there is a select list with the aria-label test-select-label');

    // Check that the jump menu select list has an ID.
    $select = $this->xpath('//select[@id="test-style-jump-menu-page-test-jump-menu-jump-menu"]');
    $this->assertGreaterThan(0, count($select), 'Ensure there is a select list with the HTML ID test-style-jump-menu-page-test-jump-menu-jump-menu');

    // Assert that there is a drupalSetting for this particular select list, and
    // by default, it is not set to open in a new window.
    $this->assertSession()->responseContains('viewsJumpMenu":{"test-style-jump-menu-page-test-jump-menu-jump-menu":{"new_window":false}}');

    // Check that the jump menu has a prompt option.
    $testUserOption = $this->xpath('//option[. = "-- Select --"]');
    $this->assertGreaterThan(0, count($testUserOption), 'Ensure there is an option with the text -- Select --');

    // Check that the jump menu has an option whose title matches the test user
    // name.
    $testUserOption = $this->xpath('//option[. = "' . $this->viewsJumpMenuTestUserName . '"]');
    $this->assertGreaterThan(0, count($testUserOption), 'Ensure there is an option with the text ' . $this->viewsJumpMenuTestUserName);

    // Check that the second jump menu option's URL matches the test user view
    // URL.
    $testUserOption = $this->xpath('//option[@data-url]');
    $this->assertGreaterThan(0, count($testUserOption), 'Ensure there options with the data-url attribute');
    $this->assertEquals($testUserOption[1]->getAttribute('data-url'), $this->viewsJumpMenuTestUserUrlString, 'Ensure there is an option with the data-url ' . $this->viewsJumpMenuTestUserUrlString);
  }

  /**
   * Tests jump menu output when items are set to open in a new window.
   */
  public function testJumpMenuCanBeConfiguredToOpenInNewWindow() {
    // Explicitly enable the "open in new window" flag.
    $testViewConfig = \Drupal::configFactory()->getEditable('views.view.test_style_jump_menu');
    $testViewConfigArray = $testViewConfig->getOriginal();
    $testViewConfigArray['display']['default']['display_options']['style']['options']['new_window'] = TRUE;
    $testViewConfig->setData($testViewConfigArray);
    $testViewConfig->save();

    // Navigate to the test view.
    $this->drupalGet('test-style-jump-menu');

    // Assert that there is a drupalSetting for this particular select list, and
    // by default, it is set to open in a new window.
    $this->assertSession()->responseContains('viewsJumpMenu":{"test-style-jump-menu-page-test-jump-menu-jump-menu":{"new_window":true}}');
  }

}
