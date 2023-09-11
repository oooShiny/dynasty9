<?php

namespace Drupal\Tests\social_post\Functional;

use Symfony\Component\HttpFoundation\Response;

/**
 * Test Social Post settings form.
 *
 * @group social_post
 *
 * @ingroup social_post
 */
class SocialPostSettingsFormTest extends SocialPostTestBase {

  /**
   * Test settings path exists.
   */
  public function testSettingsPathExists() {
    $this->drupalGet('admin/config/social-api/social-post');
    $this->assertSession()->statusCodeEquals(Response::HTTP_FORBIDDEN);

    $this->drupalLogin($this->adminUser);
    $this->drupalGet('admin/config/social-api/social-post');
    $this->assertSession()->statusCodeEquals(Response::HTTP_OK);
    $this->assertSession()->pageTextContains('Autoposting settings');
  }

}
