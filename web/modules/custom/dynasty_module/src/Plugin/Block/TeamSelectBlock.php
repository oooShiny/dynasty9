<?php

namespace Drupal\dynasty_module\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides a Block that displays a team selection dropdown list.
 *
 * @Block(
 *   id = "team_select_block",
 *   admin_label = @Translation("Team Select Form"),
 *   category = @Translation("Dynasty"),
 * )
 */
class TeamSelectBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    return \Drupal::formBuilder()->getForm('Drupal\dynasty_module\Plugin\Form\TeamSelectForm');
  }

}
