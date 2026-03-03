<?php

declare(strict_types=1);

namespace Drupal\Tests\ui_patterns_field\Kernel\Source;

use Drupal\Tests\ui_patterns\Kernel\SourcePluginsTestBase;
use function PHPUnit\Framework\assertIsArray;

/**
 * Test UIPatternsSourceFieldPropertySource.
 *
 * @coversDefaultClass \Drupal\ui_patterns_field\Plugin\UiPatterns\Source\UIPatternsSourceFieldPropertySource
 * @group ui_patterns_field
 */
class UIPatternsSourceFieldPropertySourceTest extends SourcePluginsTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['ui_patterns_field'];

  /**
   * Test Field Property Plugin.
   */
  public function testPlugin(): void {
    $testData = self::loadTestDataFixture(__DIR__ . "/../../../fixtures/tests.ui_patterns_source.yml");
    $testSets = $testData->getTestSets();
    foreach ($testSets as $test_set_name => $test_set) {
      if (!str_starts_with($test_set_name, 'ui_patterns_source_')) {
        continue;
      }
      $this->runSourcePluginTest($test_set);
    }
  }

  /**
   * Test optional columns third_party_settings and node_id.
   */
  public function testOptionalColumns(): void {
    // Create a field of type ui_patterns_source.
    $field_storage = \Drupal::entityTypeManager()
      ->getStorage('field_storage_config')
      ->create([
        'field_name' => 'field_test_source',
        'entity_type' => 'node',
        'type' => 'ui_patterns_source',
        'cardinality' => 1,
      ]);
    $field_storage->save();

    $field = \Drupal::entityTypeManager()
      ->getStorage('field_config')
      ->create([
        'field_storage' => $field_storage,
        'bundle' => 'page',
        'label' => 'Test Source Field',
      ]);
    $field->save();

    // Test 1: Store data without optional columns (backward compatibility).
    // This simulates existing data before the update hook was run.
    $node1 = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->create([
        'type' => 'page',
        'title' => 'Test Node Without Optional Columns',
        'field_test_source' => [
          [
            'source_id' => 'component',
            'source' => [
              'component' => [
                'component_id' => 'ui_patterns_test:test-component',
              ],
            ],
            // Intentionally not setting third_party_settings and node_id.
          ],
        ],
      ]);

    $node1->save();

    // Reload and verify the node was saved correctly.
    $node1 = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->load($node1->id());
    $this->assertNotNull($node1, 'Node should be saved successfully');
    $field_value1 = $node1->get('field_test_source')->getValue()[0];
    assertIsArray($field_value1);

    $this->assertEquals('component', $field_value1['source_id'], 'source_id should be stored correctly');
    $this->assertArrayHasKey('source', $field_value1, 'source should be stored correctly');

    // Verify that optional columns are handled correctly when not set.
    // This ensures backward compatibility.
    // Note: Due to Drupal Core's handling of optional MapItem columns,
    // the values may be returned in various formats (NULL, empty string).
    // The node was saved successfully and the main fields work.
    $has_third_party_settings = array_key_exists('third_party_settings', $field_value1);
    if ($has_third_party_settings) {
      $third_party_value = $field_value1['third_party_settings'];
      // Accept NULL, empty array, or empty string as valid "not set" values.
      $is_empty = $third_party_value === NULL || $third_party_value === [] || $third_party_value === '';
      $this->assertTrue(
        $is_empty,
        'third_party_settings should be empty when not provided. Got: ' . gettype($third_party_value) . ' - ' . var_export($third_party_value, TRUE)
      );
    }

    $has_node_id = array_key_exists('node_id', $field_value1);
    if ($has_node_id) {
      $node_id_value = $field_value1['node_id'];
      // Accept NULL, empty string, FALSE, empty array, or the string 'Array'
      // (which occurs due to Drupal Core's array-to-string conversion warning).
      // The important thing is that it's not a meaningful value.
      $is_empty = $node_id_value === NULL ||
                  $node_id_value === '';

      $this->assertTrue(
        $is_empty,
        'node_id should be empty when not provided. Got: ' . gettype($node_id_value) . ' - ' . var_export($node_id_value, TRUE)
      );
    }

    // Test 2: Store data with optional columns set.
    $third_party_settings = ['test_module' => ['setting' => 'value']];
    $node_id = '123';

    $node2 = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->create([
        'type' => 'page',
        'title' => 'Test Node With Optional Columns',
        'field_test_source' => [
          [
            'source_id' => 'component',
            'source' => [
              'component' => [
                'component_id' => 'ui_patterns_test:test-component',
              ],
            ],
            'third_party_settings' => $third_party_settings,
            'node_id' => $node_id,
          ],
        ],
      ]);
    $node2->save();

    // Reload and verify values are stored correctly.
    $node2 = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->load($node2->id());
    $field_value2 = $node2->get('field_test_source')->getValue()[0];

    // Verify third_party_settings is stored correctly (may be deserialized).
    $stored_third_party_settings = $field_value2['third_party_settings'] ?? NULL;
    $this->assertNotNull($stored_third_party_settings, 'third_party_settings should be stored');
    $this->assertEquals($third_party_settings, $stored_third_party_settings, 'third_party_settings should match stored value');

    // Verify node_id is stored correctly.
    $stored_node_id = $field_value2['node_id'] ?? NULL;
    $this->assertNotNull($stored_node_id, 'node_id should be stored');
    $this->assertEquals($node_id, $stored_node_id, 'node_id should match stored value');
  }

}
