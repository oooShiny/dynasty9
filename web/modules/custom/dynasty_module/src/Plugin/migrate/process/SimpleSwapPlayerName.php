<?php

namespace Drupal\dynasty_module\Plugin\migrate\process;

use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\Row;

/**
 * Swap player name from "Lastname Firstname" to "Firstname Lastname".
 *
 * This is a simpler version that does NOT add position suffixes for duplicates.
 * Use this when you want to update existing players instead of creating new ones.
 *
 * @MigrateProcessPlugin(
 *   id = "simple_swap_player_name"
 * )
 */
class SimpleSwapPlayerName extends ProcessPluginBase {

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

    return $swapped_name;
  }

}
