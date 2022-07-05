<?php

namespace Drupal\dynasty_module\Plugin\migrate\process;

use Drupal\migrate\Annotation\MigrateProcessPlugin;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Perform custom value transformations.
 *
 * @MigrateProcessPlugin(
 *   id = "calc_win_loss"
 * )
 *
 * Calculate Win or Loss based on score:
 *
 * @code
 * field_text:
 *   plugin: calc_win_loss
 *   source: text
 * @endcode
 *
 */
class WinLoss extends ProcessPluginBase {
  /**
   * {@inheritdoc}
   */

  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    $location = $this->configuration['location'];
    if ($location == 'home') {
      $pats_score = $row->getSourceProperty('score_home');
      $opp_score = $row->getSourceProperty('score_away');
    }
    else {
      $pats_score = $row->getSourceProperty('score_away');
      $opp_score = $row->getSourceProperty('score_home');
    }

    if ($pats_score > $opp_score) {
      return 'Win';
    }
    elseif ($pats_score < $opp_score) {
      return 'Loss';
    }
    return 'Tie';
  }
}
