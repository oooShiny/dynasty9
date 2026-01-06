<?php

namespace Drupal\dynasty_module\Plugin\migrate\process;

use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\Row;

/**
 * Create a player bio from CSV data.
 *
 * @MigrateProcessPlugin(
 *   id = "player_bio"
 * )
 */
class PlayerBio extends ProcessPluginBase {

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    return self::createBio($value);
  }

  /**
   * Create bio text from player data.
   *
   * @param array $value
   *   Array with College, Years, GP, GS, Number values.
   *
   * @return string
   *   Formatted bio text.
   */
  public static function createBio($value) {
    if (!is_array($value) || count($value) < 5) {
      return '';
    }

    [$college, $years, $gp, $gs, $number] = $value;

    $bio_parts = [];

    if (!empty($college)) {
      $bio_parts[] = "<strong>College:</strong> {$college}";
    }

    if (!empty($years)) {
      $bio_parts[] = "<strong>Years with Patriots:</strong> {$years}";
    }

    if (!empty($number)) {
      $bio_parts[] = "<strong>Jersey Number(s):</strong> {$number}";
    }

    $stats = [];
    if (!empty($gp)) {
      $stats[] = "{$gp} GP";
    }
    if (!empty($gs)) {
      $stats[] = "{$gs} GS";
    }

    if (!empty($stats)) {
      $bio_parts[] = "<strong>Career Stats:</strong> " . implode(', ', $stats);
    }

    return implode("<br>\n", $bio_parts);
  }

}
