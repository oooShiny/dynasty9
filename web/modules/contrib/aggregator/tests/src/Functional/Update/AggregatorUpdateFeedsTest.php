<?php

namespace Drupal\Tests\aggregator\Functional\Update;

use Drupal\FunctionalTests\Update\UpdatePathTestBase;

/**
 * Tests updates to Feed entities.
 *
 * @group Update
 * @group aggregator
 */
class AggregatorUpdateFeedsTest extends UpdatePathTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setDatabaseDumpFiles() {
    $this->databaseDumpFiles = [
      $this->root . '/core/modules/system/tests/fixtures/update/drupal-10.3.0.filled.standard.php.gz',
      __DIR__ . '/../../../fixtures/update/aggregator.php',
    ];
  }

  /**
   * @covers aggregator_update_8605
   */
  public function testUpdateHookN(): void {
    $this->runUpdates();
    $update_manager = \Drupal::entityDefinitionUpdateManager();
    $this->assertNull($update_manager->getFieldStorageDefinition('hash', 'aggregator_feed'));

  }

}
