<?php

declare(strict_types=1);

namespace Drupal\Tests\aggregator\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Tests config dependencies for the module.
 *
 * @group aggregator
 */
final class ConfigDependenciesTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'aggregator',
    'options',
    'filter',
  ];

  /**
   * {@inheritdoc}
   */
  public function setUp(): void {
    parent::setUp();
    $this->installConfig(['filter', 'aggregator']);
    $this->installEntitySchema('aggregator_feed');
    $this->installEntitySchema('aggregator_item');
  }

  /**
   * Tests filter dependencies.
   */
  public function testFilterDependency(): void {
    $filter_storage = $this->container->get('entity_type.manager')->getStorage('filter_format');
    $this->assertNotNull($filter_storage->load('aggregator_html'));

    $this->container->get('module_installer')->uninstall(['aggregator']);
    $this->assertNull($filter_storage->loadUnchanged('aggregator_html'));
  }

}
