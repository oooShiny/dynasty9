<?php

namespace Drupal\Tests\aggregator\Kernel;

use Drupal\aggregator\Entity\Item;
use Drupal\KernelTests\Core\Entity\EntityKernelTestBase;

/**
 * Tests the deprecations of Aggregator.
 *
 * @group legacy
 * @group aggregator
 */
class AggregatorLegacyItemTest extends EntityKernelTestBase {

  /**
   * Modules to install.
   *
   * @var array
   */
  protected static $modules = ['aggregator', 'options'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('aggregator_item');
  }

  /**
   * @covers \Drupal\aggregator\Entity\Item::buildUri
   */
  public function testDeprecationItemBuildUri() {
    $item = Item::create([
      'link' => 'https://example.com/feed.xml',
    ]);
    $this->expectDeprecation('Item::buildUri() is deprecated in aggregator:2.2.0 and is removed from aggregator:3.0.0. Use Item::buildItemUri() instead. See https://www.drupal.org/node/3386907.');
    $url = Item::buildUri($item);
    $this->assertSame('https://example.com/feed.xml', $url->toString());
  }

}
