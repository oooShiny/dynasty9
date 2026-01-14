<?php

namespace Drupal\dynasty_module\Plugin\migrate\process;

use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\Row;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Look up existing player node by name and jersey number.
 *
 * This allows the migration to update existing players instead of creating duplicates.
 *
 * Usage:
 * @code
 * process:
 *   nid:
 *     plugin: player_entity_lookup
 *     source_title: Name
 *     source_number: Number
 * @endcode
 *
 * @MigrateProcessPlugin(
 *   id = "player_entity_lookup"
 * )
 */
class PlayerEntityLookup extends ProcessPluginBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    // Get the player name (already swapped) from the row
    $player_name = $row->getDestinationProperty('title');

    // If title hasn't been processed yet, we need to process it first
    if (empty($player_name)) {
      $raw_name = $row->getSourceProperty($this->configuration['source_title'] ?? 'Name');
      // Apply the same name swapping logic
      $name_parts = explode(' ', trim($raw_name), 2);
      if (count($name_parts) === 2) {
        $player_name = trim($name_parts[1]) . ' ' . trim($name_parts[0]);
      } else {
        $player_name = $raw_name;
      }
    }

    if (empty($player_name)) {
      // Can't do lookup without player name
      return NULL;
    }

    // Query for existing player node by name only
    // This will find existing players regardless of whether they have
    // a jersey number or position suffix already
    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->condition('type', 'player')
      ->condition('title', $player_name)
      ->accessCheck(FALSE)
      ->range(0, 1);

    $nids = $query->execute();

    if (!empty($nids)) {
      // Return the existing node ID to update it
      return reset($nids);
    }

    // No existing node found, return NULL to create new one
    return NULL;
  }

}
