<?php

namespace Drupal\dynasty_module\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides a Block that displays games from this date in history.
 *
 * @Block(
 *   id = "season_select_block",
 *   admin_label = @Translation("Season Select Form"),
 *   category = @Translation("Dynasty"),
 * )
 */
class SeasonSelectBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    return \Drupal::formBuilder()->getForm('Drupal\dynasty_module\Plugin\Form\SeasonSelectForm');
  }

}
