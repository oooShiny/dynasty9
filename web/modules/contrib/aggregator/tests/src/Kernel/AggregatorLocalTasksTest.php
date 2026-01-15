<?php

namespace Drupal\Tests\aggregator\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Tests existence of aggregator local tasks.
 *
 * @group aggregator
 */
class AggregatorLocalTasksTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['aggregator'];

  /**
   * Tests local task existence.
   *
   * @dataProvider getAggregatorAdminRoutes
   */
  public function testAggregatorAdminLocalTasks($route) {
    $this->assertLocalTasks($route, [
      0 => ['aggregator.admin_overview', 'aggregator.admin_settings'],
    ]);
  }

  /**
   * Provides a list of routes to test.
   */
  public static function getAggregatorAdminRoutes(): array {
    return [
      ['aggregator.admin_overview'],
      ['aggregator.admin_settings'],
    ];
  }

  /**
   * Checks aggregator source tasks.
   *
   * @dataProvider getAggregatorSourceRoutes
   */
  public function testAggregatorSourceLocalTasks($route) {
    $this->assertLocalTasks($route, [
      0 => [
        'entity.aggregator_feed.canonical',
        'entity.aggregator_feed.edit_form',
        'entity.aggregator_feed.delete_form',
      ],
    ]);
  }

  /**
   * Provides a list of source routes to test.
   */
  public static function getAggregatorSourceRoutes(): array {
    return [
      ['entity.aggregator_feed.canonical'],
      ['entity.aggregator_feed.edit_form'],
    ];
  }

  /**
   * Asserts integration for local tasks.
   *
   * @param string $route_name
   *   Route name to base task building on.
   * @param array $expected_tasks
   *   A list of tasks groups by level expected at the given route.
   */
  protected function assertLocalTasks(string $route_name, array $expected_tasks): void {
    $manager = $this->container->get('plugin.manager.menu.local_task');
    $route_tasks = array_map(function (array $tasks): array {
      return array_keys($tasks);
    }, $manager->getLocalTasksForRoute($route_name));
    $this->assertSame($expected_tasks, $route_tasks);
  }

}
