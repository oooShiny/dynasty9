<?php

namespace Drupal\dynasty_module\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides a Block that displays a player selection dropdown list.
 *
 * @Block(
 *   id = "player_select_block",
 *   admin_label = @Translation("Player Select Form"),
 *   category = @Translation("Dynasty"),
 * )
 */
class PlayerSelectBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    return \Drupal::formBuilder()->getForm('Drupal\dynasty_module\Plugin\Form\PlayerSelectForm');
  }

}
