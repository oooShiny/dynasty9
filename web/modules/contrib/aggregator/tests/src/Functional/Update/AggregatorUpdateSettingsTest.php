<?php

namespace Drupal\Tests\aggregator\Functional\Update;

use Drupal\filter\Entity\FilterFormat;
use Drupal\FunctionalTests\Update\UpdatePathTestBase;

/**
 * Tests setting updates and deletions.
 *
 * @group Update
 * @group aggregator
 */
class AggregatorUpdateSettingsTest extends UpdatePathTestBase {

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
   * @covers aggregator_update_8602
   * @covers aggregator_update_8603
   * @covers aggregator_update_8607
   * @covers aggregator_update_8608
   */
  public function testUpdateHookN(): void {
    $old_settings = \Drupal::config('aggregator.settings');

    $this->assertSame('<a> <b> <br> <dd> <dl> <dt> <em> <i> <li> <ol> <p> <strong> <u> <ul>', $old_settings->get('items.allowed_html'));
    $this->assertNull($old_settings->get('normalize_post_dates'));
    $this->assertNull(FilterFormat::load('aggregator_html'));

    $this->runUpdates();

    // Ensure items.allowed_html is deleted and the filter format exists.
    $new_settings = \Drupal::config('aggregator.settings');
    $this->assertNull($new_settings->get('items.allowed_html'));
    $format = FilterFormat::load('aggregator_html');
    $this->assertNotNull($format);
    $filter = $format->filters('filter_html');
    $filter_config = $filter->getConfiguration();
    $this->assertSame('<a> <b> <br> <dd> <dl> <dt> <em> <i> <li> <ol> <p> <strong> <u> <ul>', $filter_config['settings']['allowed_html']);
    $this->assertSame(['module' => ['aggregator']], $format->getDependencies());

    // Ensure items.teaser_length is deleted.
    $settings = \Drupal::config('aggregator.settings');
    $this->assertNull($settings->get('items.teaser_length'));
    $this->assertFalse($settings->get('normalize_post_dates'));
  }

}
