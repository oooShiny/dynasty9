<?php

namespace Drupal\dynasty_module\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides a Block that displays a player selection dropdown list.
 *
 * @Block(
 *   id = "play_type_select_block",
 *   admin_label = @Translation("Play Type Select Form"),
 *   category = @Translation("Dynasty"),
 * )
 */
class PlayTypeSelectBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    return \Drupal::formBuilder()->getForm('Drupal\dynasty_module\Plugin\Form\PlayTypeSelectForm');
  }

}
