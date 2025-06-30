<?php

namespace Drupal\Tests\plausible\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests whether the Plausible snippet is added to the page.
 *
 * @group plausible
 */
final class PlausibleSnippetTest extends BrowserTestBase {

  const SCRIPT_SELECTOR = 'script[async][defer][data-domain][src="https://plausible.io/js/plausible.js"]';

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'plausible',
  ];

  /**
   * An editor role.
   *
   * @var \Drupal\user\RoleInterface
   */
  protected $editorRole;

  /**
   * A user with the editor role.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $editorUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->editorRole = $this->createRole(['access administration pages'], 'editor');
    $this->editorUser = $this->createUser([], NULL, FALSE, ['roles' => ['editor']]);
  }

  /**
   * Tests whether the snippet is added to the page on a fresh install.
   */
  public function testTheSnippetIsAddedToThePage() {
    $this->drupalGet('<front>');
    $this->assertPlausibleScriptExists();
  }

  /**
   * Tests whether the snippet is added with various page visibility settings.
   */
  public function testTheSnippetIsOnlyAddedForSpecifiedPaths() {
    $this->drupalLogin($this->editorUser);

    $settings = $this->config('plausible.settings');
    $settings->set('visibility.user_role_mode', 0);
    $settings->set('visibility.user_role_roles', []);

    // Disabled.
    $settings->set('visibility.request_path_mode', 0);
    $settings->set('visibility.request_path_pages', '');
    $settings->save();

    $this->drupalGet('/other-path');
    $this->assertPlausibleScriptExists();

    // Visible on every page except /some-path.
    $settings->set('visibility.request_path_mode', 1);
    $settings->set('visibility.request_path_pages', '/some-path');
    $settings->save();

    $this->drupalGet('/other-path');
    $this->assertPlausibleScriptExists();

    $this->drupalGet('/some-path');
    $this->assertPlausibleScriptNotExists();

    // Visible on every page.
    $settings->set('visibility.request_path_mode', 1);
    $settings->set('visibility.request_path_pages', '');
    $settings->save();

    $this->drupalGet('/other-path');
    $this->assertPlausibleScriptExists();

    $this->drupalGet('/some-path');
    $this->assertPlausibleScriptExists();

    // Visible only on /some-path.
    $settings->set('visibility.request_path_mode', 2);
    $settings->set('visibility.request_path_pages', '/some-path');
    $settings->save();

    $this->drupalGet('/other-path');
    $this->assertPlausibleScriptNotExists();

    $this->drupalGet('/some-path');
    $this->assertPlausibleScriptExists();

    // Visible on no pages.
    $settings->set('visibility.request_path_mode', 2);
    $settings->set('visibility.request_path_pages', '');
    $settings->save();

    $this->drupalGet('/other-path');
    $this->assertPlausibleScriptNotExists();

    $this->drupalGet('/some-path');
    $this->assertPlausibleScriptNotExists();
  }

  /**
   * Tests whether the snippet is added with various role visibility settings.
   */
  public function testTheSnippetIsOnlyAddedForSpecifiedRoles() {
    $settings = $this->config('plausible.settings');
    $settings->set('visibility.request_path_mode', 0);
    $settings->set('visibility.request_path_pages', '');

    // Disabled.
    $settings->set('visibility.user_role_mode', 0);
    $settings->save();

    $this->drupalGet('<front>');
    $this->assertPlausibleScriptExists();

    // Only visible for the editor role.
    $settings->set('visibility.user_role_mode', 1);
    $settings->set('visibility.user_role_roles.editor', 'editor');
    $settings->save();

    $this->drupalGet('<front>');
    $this->assertPlausibleScriptNotExists();

    $this->drupalLogin($this->editorUser);
    $this->drupalGet('<front>');
    $this->assertPlausibleScriptExists();

    // Visible for no roles.
    $settings->set('visibility.user_role_mode', 1);
    $settings->set('visibility.user_role_roles', []);
    $settings->save();

    $this->drupalLogout();
    $this->drupalGet('<front>');
    $this->assertPlausibleScriptNotExists();

    $this->drupalLogin($this->editorUser);
    $this->drupalGet('<front>');
    $this->assertPlausibleScriptNotExists();

    // Visible for all roles except editors.
    $settings->set('visibility.user_role_mode', 2);
    $settings->set('visibility.user_role_roles.editor', 'editor');
    $settings->save();

    $this->drupalLogout();
    $this->drupalGet('<front>');
    $this->assertPlausibleScriptExists();

    $this->drupalLogin($this->editorUser);
    $this->drupalGet('<front>');
    $this->assertPlausibleScriptNotExists();

    // Visible for all roles.
    $settings->set('visibility.user_role_mode', 2);
    $settings->set('visibility.user_role_roles.editor', 'editor');
    $settings->save();

    $this->drupalLogout();
    $this->drupalGet('<front>');
    $this->assertPlausibleScriptExists();

    $this->drupalLogin($this->editorUser);
    $this->drupalGet('<front>');
    $this->assertPlausibleScriptNotExists();
  }

  /**
   * Assert whether the snippet exists in the current session.
   */
  protected function assertPlausibleScriptExists() {
    $this->assertSession()->elementExists('css', self::SCRIPT_SELECTOR);
  }

  /**
   * Assert whether the snippet does not exist in the current session.
   */
  protected function assertPlausibleScriptNotExists() {
    $this->assertSession()->elementNotExists('css', self::SCRIPT_SELECTOR);
  }

}
