<?php

namespace Drupal\dynasty_module\Plugin\migrate\process;

use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\Row;

/**
 * Parse player years from various formats.
 *
 * Examples:
 * - "2004" → [2004]
 * - "1971-85" → [1971, 1972, ..., 1985]
 * - "1971-85 87" → [1971, 1972, ..., 1985, 1987]
 * - "2000-03 05-07" → [2000, 2001, 2002, 2003, 2005, 2006, 2007]
 *
 * @MigrateProcessPlugin(
 *   id = "parse_player_years"
 * )
 */
class ParsePlayerYears extends ProcessPluginBase {

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    if (empty($value)) {
      return NULL;
    }

    $all_years = [];

    // Split by spaces to handle multiple ranges/years
    $parts = preg_split('/\s+/', trim($value));

    foreach ($parts as $part) {
      // Check for year range like "1990-91" or "2000-03"
      if (preg_match('/(\d{4})-(\d{2,4})/', $part, $matches)) {
        $start_year = (int) $matches[1];
        $end_year_str = $matches[2];

        // If end year is 2 digits, use the century from start year
        if (strlen($end_year_str) === 2) {
          $century = floor($start_year / 100) * 100;
          $end_year = $century + (int) $end_year_str;

          // Handle century rollover (e.g., 1999-00 should be 1999-2000)
          if ($end_year < $start_year) {
            $end_year += 100;
          }
        }
        else {
          $end_year = (int) $end_year_str;
        }

        // Generate all years in the range
        for ($year = $start_year; $year <= $end_year; $year++) {
          $all_years[] = $year;
        }
      }
      // Check for single year like "2004" or "87" (2-digit year)
      elseif (preg_match('/^(\d{2,4})$/', $part, $matches)) {
        $year_str = $matches[1];

        // If it's a 2-digit year, assume 19xx or 20xx based on context
        if (strlen($year_str) === 2) {
          $year_num = (int) $year_str;
          // Assume 19xx for years 60-99, 20xx for years 00-59
          $year = ($year_num >= 60) ? 1900 + $year_num : 2000 + $year_num;
        }
        else {
          $year = (int) $year_str;
        }

        $all_years[] = $year;
      }
    }

    // Remove duplicates and sort
    $all_years = array_unique($all_years);
    sort($all_years);

    return !empty($all_years) ? $all_years : NULL;
  }

}
