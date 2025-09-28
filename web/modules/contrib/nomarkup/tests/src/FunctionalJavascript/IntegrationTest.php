<?php

namespace Drupal\Tests\nomarkup\FunctionalJavascript;

use Behat\Mink\Exception\ElementNotFoundException;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;

/**
 * A nomarkup integration test.
 *
 * @group nomarkup
 */
class IntegrationTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'field', 'field_ui', 'nomarkup'];

  /**
   * An admin user.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser;

  /**
   * A node.
   *
   * @var \Drupal\node\NodeInterface
   */
  protected $node;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'starterkit_theme';

  /**
   * {@inheritdoc}
   */
  public function setUp(): void {
    parent::setUp();
    $this->drupalCreateContentType(['type' => 'article', 'name' => 'Article']);
    $this->node = $this->drupalCreateNode([
      'title' => $this->randomString(),
      'type' => 'article',
      'body' => 'Body field value.',
    ]);
    $this->adminUser = $this->drupalCreateUser(['access content', 'administer node display']);
    $this->drupalLogin($this->adminUser);
  }

  /**
   * Test the basic settings.
   */
  public function testBasicSettings() {
    $assert_session = $this->assertSession();
    $manage_display = '/admin/structure/types/manage/article/display';
    $this->drupalGet($manage_display);

    $this->submitForm([], 'body_settings_edit');
    $this->submitForm(['fields[body][settings_edit_form][third_party_settings][nomarkup][enabled]' => TRUE], 'Save');

    $this->drupalGet('/node/' . $this->node->id());
    try {
      $assert_session->elementExists('css', '.field--name-body');
      throw new \AssertionError('Field wrapper should be skipped.');
    }
    catch (ElementNotFoundException $exception) {
      $this->assertSame('Element matching css ".field--name-body" not found.', $exception->getMessage());
    }
    $assert_session->pageTextContainsOnce('Body field value.');
  }

  /**
   * Test the basic settings turned off.
   */
  public function testBasicSettingsOff() {
    $assert_session = $this->assertSession();
    $manage_display = '/admin/structure/types/manage/article/display';
    $this->drupalGet($manage_display);

    $this->submitForm([], 'body_settings_edit');
    $this->submitForm(['fields[body][settings_edit_form][third_party_settings][nomarkup][enabled]' => FALSE], 'Save');

    $this->drupalGet('/node/' . $this->node->id());
    $assert_session->elementExists('css', '.field--name-body');
    $assert_session->pageTextContainsOnce('Body field value.');
  }

}
