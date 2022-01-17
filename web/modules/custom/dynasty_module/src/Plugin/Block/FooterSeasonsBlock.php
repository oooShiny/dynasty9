<?php

namespace Drupal\dynasty_module\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides a Block that displays games from this date in history.
 *
 * @Block(
 *   id = "footer_seasons_block",
 *   admin_label = @Translation("Footer Seasons Block"),
 *   category = @Translation("Dynasty"),
 * )
 */
class FooterSeasonsBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    $seasons = [];
    for ($i = 2000; $i < 2020; $i++) {
      $seasons[$i] = 'search/games?f[0]=season:(min:' . $i . ',max:' . $i . ')';
    }
    return [
      '#theme' => 'footer_seasons_block',
      '#seasons' => $seasons
    ];
  }

}
