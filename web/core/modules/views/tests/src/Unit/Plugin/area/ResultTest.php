<?php

declare(strict_types=1);

namespace Drupal\Tests\views\Unit\Plugin\area;

use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\views\Entity\View;
use Drupal\views\Plugin\views\pager\PagerPluginBase;
use Drupal\views\Plugin\ViewsPluginManager;
use Drupal\views\ViewExecutable;
use Drupal\views\Plugin\views\area\Result;
use Drupal\views\ViewsData;
use Prophecy\Argument;

/**
 * @coversDefaultClass \Drupal\views\Plugin\views\area\Result
 * @group views
 */
class ResultTest extends UnitTestCase {

  const SEPARATOR_COMMA = ',';
  const SEPARATOR_PERIOD = '.';
  const SEPARATOR_SPACE = ' ';
  const SEPARATOR_THIN_SPACE = "\t";
  const SEPARATOR_APOSTROPHE = "'";
  const SEPARATOR_NONE = '';

  /**
   * The view executable object.
   *
   * @var \Drupal\views\ViewExecutable
   */
  protected $view;

  /**
   * The Result handler.
   *
   * @var \Drupal\views\Plugin\views\area\Result
   */
  protected $resultHandler;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $storage = $this->prophesize(View::class);
    $storage->label()->willReturn('ResultTest');
    $storage->set(Argument::cetera())->willReturn(NULL);

    $user = $this->prophesize(AccountInterface::class)->reveal();
    $views_data = $this->prophesize(ViewsData::class)->reveal();
    $route_provider = $this->prophesize(RouteProviderInterface::class)->reveal();
    $display_plugin_manager = $this->prophesize(ViewsPluginManager::class)->reveal();
    $this->view = new ViewExecutable($storage->reveal(), $user, $views_data, $route_provider, $display_plugin_manager);

    $this->resultHandler = new Result([], 'result', []);
    $this->resultHandler->view = $this->view;
  }

  /**
   * Tests the query method.
   */
  public function testQuery(): void {
    $this->assertNull($this->view->get_total_rows);
    // @total should set get_total_rows.
    $this->resultHandler->options['content'] = '@total';
    $this->resultHandler->query();
    $this->assertTrue($this->view->get_total_rows);
    // A different token should not.
    $this->view->get_total_rows = NULL;
    $this->resultHandler->options['content'] = '@current_page';
    $this->resultHandler->query();
    $this->assertNull($this->view->get_total_rows);
  }

  /**
   * Tests the rendered output of the Result area handler.
   *
   * @param string $content
   *   The content to use when rendering the handler.
   * @param string $thousand_separator
   *   The thousand separator to use
   * @param string $expected
   *   The expected content string.
   * @param int $items_per_page
   *   The items per page of the configuration.
   * @param int $current_page
   *   The current page of the view
   * @param int $total_rows
   *   The total rows found in the view
   *
   * @dataProvider providerTestResultArea
   */
  public function testResultArea($content, $thousand_separator, $expected, $items_per_page = 0, $current_page = 0, $total_rows = 1000): void {
    $this->setupViewPager($items_per_page, $current_page, $total_rows);
    $this->setupViewPager($items_per_page);
    $this->resultHandler->options['content'] = $content;
    $this->resultHandler->options['thousand_separator'] = $thousand_separator;
    $this->assertEquals(['#markup' => $expected], $this->resultHandler->render());
  }

  /**
   * Data provider for testResultArea.
   *
   * @return array
   */
  public static function providerTestResultArea() {
    return [
      ['@label', self::SEPARATOR_NONE, 'ResultTest'],
      ['@start', self::SEPARATOR_COMMA, '1'],
      ['@start', self::SEPARATOR_COMMA, '1', 1],
      ['@start', self::SEPARATOR_NONE, '1000', 1, 999, 1000],
      ['@start', self::SEPARATOR_COMMA, '1,000', 1, 999, 1000],
      ['@end', self::SEPARATOR_COMMA, '1,000'],
      ['@end', self::SEPARATOR_PERIOD, '1', 1],
      ['@end', self::SEPARATOR_PERIOD, '1.000', 1, 999, 1000],
      ['@total', self::SEPARATOR_PERIOD, '1.000'],
      ['@total', self::SEPARATOR_SPACE, '1 000', 1],
      ['@per_page', self::SEPARATOR_SPACE, '0'],
      ['@per_page', self::SEPARATOR_SPACE, '1', 1],
      ['@current_page', self::SEPARATOR_SPACE, '1'],
      ['@current_page', self::SEPARATOR_SPACE, '1', 1],
      ['@current_record_count', self::SEPARATOR_THIN_SPACE, '1000'],
      ['@current_record_count', self::SEPARATOR_APOSTROPHE, '1', 1],
      ['@page_count', self::SEPARATOR_SPACE, '1'],
      // @page_count doesn't honor the thousand separator.
      ['@page_count', self::SEPARATOR_PERIOD, '1000', 1],
      ['@start | @end | @total', self::SEPARATOR_APOSTROPHE, "1 | 1'000 | 1'000"],
      ['@start | @end | @total', self::SEPARATOR_SPACE, '1 | 100 | 1 000', 100],
    ];
  }

  /**
   * Sets up a mock pager on the view executable object.
   *
   * @param int $items_per_page
   *   The value to return from getItemsPerPage().
   * @param int $current_page
   *   The value to return from getCurrentPage()
   * @param int $total_rows
   *   The value to set the view total_rows property
   */
  protected function setupViewPager($items_per_page = 0, $current_page = 0, $total_rows = 1000) {
    $pager = $this->prophesize(PagerPluginBase::class);
    $pager->getItemsPerPage()
      ->willReturn($items_per_page)
      ->shouldBeCalledTimes(1);
    $pager->getCurrentPage()
      ->willReturn($current_page)
      ->shouldBeCalledTimes(1);

    $this->view->pager = $pager->reveal();
    $this->view->style_plugin = new \stdClass();
    $this->view->total_rows = $total_rows;
    $this->view->result = [1, 2, 3, 4, 5];
  }

}
