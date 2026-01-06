<?php

namespace Drupal\dynasty_module\Plugin\migrate\process;

use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\Row;

/**
 * Extract all years from a year range string.
 *
 * Examples:
 * - "1990-91" → [1990, 1991]
 * - "2004" → [2004]
 * - "1972-73" → [1972, 1973]
 * - "2000-03" → [2000, 2001, 2002, 2003]
 *
 * @MigrateProcessPlugin(
 *   id = "extract_first_year"
 * )
 */
class ExtractFirstYear extends ProcessPluginBase {

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    if (empty($value)) {
      return NULL;
    }

    $years = [];

    // Check for year range like "1990-91" or "2000-03"
    if (preg_match('/(\d{4})-(\d{2,4})/', $value, $matches)) {
      $start_year = (int) $matches[1];
      $end_year_str = $matches[2];

      // If end year is 2 digits, use the century from start year
      if (strlen($end_year_str) === 2) {
        $century = floor($start_year / 100) * 100;
        $end_year = $century + (int) $end_year_str;
      }
      else {
        $end_year = (int) $end_year_str;
      }

      // Generate all years in the range
      for ($year = $start_year; $year <= $end_year; $year++) {
        $years[] = $year;
      }
    }
    // Check for single year like "2004"
    elseif (preg_match('/(\d{4})/', $value, $matches)) {
      $years[] = (int) $matches[1];
    }

    return !empty($years) ? $years : NULL;
  }

}
