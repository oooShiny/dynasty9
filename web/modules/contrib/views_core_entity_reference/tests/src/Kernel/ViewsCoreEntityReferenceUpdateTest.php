<?php

declare(strict_types=1);

namespace Drupal\Tests\views_core_entity_reference\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Tests the Entity Reference still works without the test sub-module.
 *
 * @group views
 */
class ViewsCoreEntityReferenceUpdateTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'node',
    'user',
    'field',
    'views',
    'views_core_entity_reference',
    'test_as_a_reference',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig('test_as_a_reference');
  }

  /**
   * Tests that the core opt-in module is disabled.
   */
  public function testUpdateHook() {

    // Get the original config.
    $view_config = \Drupal::configFactory()->get('views.view.test_view_as_a_reference');
    $filters = $view_config->get('display.default.display_options.filters');
    $expected = [
      'status' => [
        'id' => 'status',
        'table' => 'node_field_data',
        'field' => 'status',
        'entity_type' => 'node',
        'entity_field' => 'status',
        'plugin_id' => 'boolean',
        'value' => '1',
        'group' => 1,
        'expose' => [
          'operator' => '',
        ],
      ],
      'type' => [
        'id' => 'type',
        'table' => 'node_field_data',
        'field' => 'type',
        'entity_type' => 'node',
        'entity_field' => 'type',
        'plugin_id' => 'bundle',
        'value' => [
          'page' => 'page',
        ],
      ],
      'field_related_articles_target_id' => [
        'id' => 'field_related_articles_target_id',
        'table' => 'node__field_related_articles',
        'field' => 'field_related_articles_target_id',
        'relationship' => 'none',
        'group_type' => 'group',
        'admin_label' => '',
        'plugin_id' => 'entity_reference',
        'operator' => 'or',
        'value' => [],
        'group' => 1,
        'exposed' => TRUE,
        'expose' => [
          'operator_id' => 'field_related_articles_target_id_op',
          'label' => 'Related articles (field_related_articles) as a Reference filter',
          'description' => '',
          'use_operator' => FALSE,
          'operator' => 'field_related_articles_target_id_op',
          'operator_limit_selection' => FALSE,
          'operator_list' => [],
          'identifier' => 'field_related_articles_target_id_reference',
          'required' => FALSE,
          'remember' => FALSE,
          'multiple' => FALSE,
          'remember_roles' => [
            'authenticated' => 'authenticated',
            'anonymous' => '0',
            'content_editor' => '0',
            'administrator' => '0',
          ],
          'reduce' => FALSE,
        ],
        'is_grouped' => FALSE,
        'group_info' => [
          'label' => '',
          'description' => '',
          'identifier' => '',
          'optional' => TRUE,
          'widget' => 'select',
          'multiple' => FALSE,
          'remember' => FALSE,
          'default_group' => 'All',
          'default_group_multiple' => [],
          'group_items' => [],
        ],
        'reduce_duplicates' => FALSE,
        'sub_handler' => 'default:node',
        'widget' => 'select',
        'sub_handler_settings' => [
          'target_bundles' => [
            'article' => 'article',
          ],
          'sort' => [
            'field' => '_none',
            'direction' => 'ASC',
          ],
          'auto_create' => FALSE,
          'auto_create_bundle' => '',
        ],
      ],
      'field_related_users_target_id' => [
        'id' => 'field_related_users_target_id',
        'table' => 'node__field_related_users',
        'field' => 'field_related_users_target_id',
        'relationship' => 'none',
        'group_type' => 'group',
        'admin_label' => '',
        'plugin_id' => 'entity_reference',
        'operator' => 'or',
        'value' => [],
        'group' => 1,
        'exposed' => TRUE,
        'expose' => [
          'operator_id' => 'field_related_users_target_id_op',
          'label' => 'Related users (field_related_users) as a Reference filter',
          'description' => '',
          'use_operator' => FALSE,
          'operator' => 'field_related_users_target_id_op',
          'operator_limit_selection' => FALSE,
          'operator_list' => [],
          'identifier' => 'field_related_users_target_id_reference',
          'required' => FALSE,
          'remember' => FALSE,
          'multiple' => FALSE,
          'remember_roles' => [
            'authenticated' => 'authenticated',
            'anonymous' => '0',
            'content_editor' => '0',
            'administrator' => '0',
          ],
          'reduce' => FALSE,
        ],
        'is_grouped' => FALSE,
        'group_info' => [
          'label' => '',
          'description' => '',
          'identifier' => '',
          'optional' => TRUE,
          'widget' => 'select',
          'multiple' => FALSE,
          'remember' => FALSE,
          'default_group' => 'All',
          'default_group_multiple' => [],
          'group_items' => [],
        ],
        'reduce_duplicates' => FALSE,
        'sub_handler' => 'default:user',
        'widget' => 'select',
        'sub_handler_settings' => [
          'target_bundles' => NULL,
          'sort' => [
            'field' => '_none',
            'direction' => 'ASC',
          ],
          'auto_create' => FALSE,
          'filter' => [
            'type' => '_none',
          ],
          'include_anonymous' => TRUE,
        ],
      ],
    ];
    // Should not yet be the same until the update.
    $this->assertNotSame($expected, $filters);

    // Run the update hook manually.
    \Drupal::moduleHandler()->loadInclude('views_core_entity_reference', 'install');
    _views_core_entity_reference_update_as_a_reference();

    // Get the updated config.
    $view_config = \Drupal::configFactory()->get('views.view.test_view_as_a_reference');
    $filters = $view_config->get('display.default.display_options.filters');
    $this->assertSame($expected, $filters);
  }

}
