<?php

namespace Drupal\dynasty_module\Plugin\migrate\process;

use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\Row;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Swap player name from "Lastname Firstname" to "Firstname Lastname".
 *
 * Also handles duplicate names by adding position in parentheses.
 *
 * @MigrateProcessPlugin(
 *   id = "swap_player_name"
 * )
 */
class SwapPlayerName extends ProcessPluginBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Track names we've already processed in this migration run.
   *
   * @var array
   */
  protected static $processedNames = [];

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
    if (empty($value)) {
      return $value;
    }

    // Swap "Lastname Firstname" to "Firstname Lastname"
    $name_parts = explode(' ', trim($value), 2);

    if (count($name_parts) === 2) {
      $swapped_name = trim($name_parts[1]) . ' ' . trim($name_parts[0]);
    }
    else {
      // Single name, no swap needed
      $swapped_name = $value;
    }

    // Check if this name already exists in database OR was already processed
    $position = $row->getSourceProperty('Position');

    if (!empty($position)) {
      // Check if we've already processed this name in this migration run
      $already_processed = isset(self::$processedNames[$swapped_name]);

      // Check if this name exists in the database
      $storage = $this->entityTypeManager->getStorage('node');
      $query = $storage->getQuery()
        ->condition('type', 'player')
        ->condition('title', $swapped_name)
        ->accessCheck(FALSE);

      $existing = $query->execute();

      // If name already exists OR already processed, add position to make it unique
      if (!empty($existing) || $already_processed) {
        $swapped_name .= ' (' . $position . ')';
      }

      // Track this name as processed
      self::$processedNames[$swapped_name] = TRUE;
    }

    return $swapped_name;
  }

}
