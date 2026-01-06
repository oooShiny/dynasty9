<?php

namespace Drupal\dynasty_module\Plugin\migrate\process;

use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\Row;

/**
 * Extract the first number from a string that may contain multiple numbers.
 *
 * @MigrateProcessPlugin(
 *   id = "extract_first_number"
 * )
 */
class ExtractFirstNumber extends ProcessPluginBase {

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    if (empty($value)) {
      return NULL;
    }

    // Handle strings like "69,85" or "69, 85" - extract first number
    if (strpos($value, ',') !== FALSE) {
      $parts = explode(',', $value);
      $value = trim($parts[0]);
    }

    // Remove any non-numeric characters and convert to integer
    $number = preg_replace('/[^0-9]/', '', $value);

    if (empty($number)) {
      return NULL;
    }

    return (int) $number;
  }

}
