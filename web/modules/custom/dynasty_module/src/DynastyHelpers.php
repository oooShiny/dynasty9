<?php

namespace Drupal\dynasty_module;

class DynastyHelpers {


  /**
   * Get passer rating based off passing attributes.
   * Attempts
   * @param $att
   * Completions
   * @param $comp
   * Passing Yards
   * @param $yds
   * Passing TDs
   * @param $td
   * Interceptions
   * @param $int
   * @return float|int
   */
  public static function passer_rating($att, $comp, $yds, $td, $int) {
    $a = (($comp/$att) - 0.3) * 5;
    $b = (($yds/$att) - 3) * .25;
    $c = ($td/$att) * 20;
    $d = 2.375 - (($int/$att) * 25);

    $passer_rating = (($a + $b + $c + $d) / 6) * 100;

    return $passer_rating;
  }
}
