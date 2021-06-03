<?php

namespace Drupal\afc_east\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\afc_east\AFCEastHelpers;

/**
 * Provides a Block that displays win % vs the AFC and NFC.
 *
 * @Block(
 *   id = "afc_conf_win_pct",
 *   admin_label = @Translation("AFC/NFC Win Pct Block"),
 *   category = @Translation("Dynasty"),
 * )
 */
class ConfWinPct extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    $divisions = AFCEastHelpers::old_divisions();
    return [
      '#theme' => 'footer_seasons_block',
      '#seasons' => $divisions
    ];
  }

}
