<?php

namespace Drupal\Tests\nomarkup\Kernel;

use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\filter\Entity\FilterFormat;
use Drupal\KernelTests\KernelTestBase;

/**
 * Test the field output under different configurations.
 *
 * @group nomarkup
 */
class FieldOutputTest extends KernelTestBase {

  /**
   * The test field name.
   *
   * @var string
   */
  protected $fieldName = 'field_test';

  /**
   * The entity type ID.
   *
   * @var string
   */
  protected $entityTypeId = 'entity_test';

  /**
   * The test entity used for testing output.
   *
   * @var \Drupal\Tests\views\Unit\Plugin\area\EntityTest
   */
  protected $entity;

  /**
   * The entity display under test.
   *
   * @var \Drupal\Core\Entity\Entity\EntityViewDisplay
   */
  protected $entityViewDisplay;

  /**
   * Modules to enable.
   *
   * @var string[]
   */
  protected static $modules = [
    'user',
    'system',
    'field',
    'text',
    'filter',
    'entity_test',
    'field_test',
    'nomarkup',
  ];

  /**
   * Test cases for the field output test.
   */
  public function fieldTestCases(): array {
    return [
      'enabled' => [
        ['enabled' => TRUE, 'separator' => '#$#'],
        'lorem ipsum',
      ],
      'disabled' => [
        ['enabled' => FALSE, 'separator' => '#$#'],
        '<div><div>field_test</div><div>lorem ipsum</div></div>',
      ],
    ];
  }

  /**
   * Test the field output.
   *
   * @dataProvider fieldTestCases
   */
  public function testFieldOutput($settings, $field_markup): void {
    // The entity display must be updated because the view method on fields
    // doesn't support passing third party settings.
    $this->entityViewDisplay->setComponent($this->fieldName, [
      'label' => 'above',
      'settings' => [],
      'type' => 'text_default',
      'third_party_settings' => [
        'nomarkup' => $settings,
      ],
    ])->setStatus(TRUE)->save();
    $field_output = $this->entity->{$this->fieldName}->view('default');
    $rendered_field_output = $this->stripWhitespace($this->container->get('renderer')
      ->renderRoot($field_output));
    $this->assertEquals($this->stripWhitespace($field_markup), $rendered_field_output);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema($this->entityTypeId);
    $this->installEntitySchema('filter_format');

    // Setup a field and an entity display.
    EntityViewDisplay::create([
      'targetEntityType' => 'entity_test',
      'bundle' => 'entity_test',
      'mode' => 'default',
    ])->save();
    FieldStorageConfig::create([
      'field_name' => $this->fieldName,
      'entity_type' => $this->entityTypeId,
      'type' => 'text',
    ])->save();
    FieldConfig::create([
      'entity_type' => $this->entityTypeId,
      'field_name' => $this->fieldName,
      'bundle' => $this->entityTypeId,
    ])->save();

    $this->entityViewDisplay = EntityViewDisplay::load('entity_test.entity_test.default');

    // Create a test entity with a test value.
    $this->entity = EntityTest::create();
    $this->entity->{$this->fieldName}->value = 'lorem ipsum';
    $this->entity->save();

    // Set the default filter format.
    FilterFormat::create([
      'format' => 'test_format',
      'name' => $this->randomMachineName(),
    ])->save();
    $this->container->get('config.factory')
      ->getEditable('filter.settings')
      ->set('fallback_format', 'test_format')
      ->save();
  }

  /**
   * Remove HTML whitespace from a string.
   *
   * @param string $string
   *   The input string.
   *
   * @return string
   *   The whitespace cleaned string.
   */
  protected function stripWhitespace($string): string {
    $no_whitespace = preg_replace('/\s{2,}/', '', $string);
    $no_whitespace = str_replace("\n", '', $no_whitespace);
    return $no_whitespace;
  }

}
