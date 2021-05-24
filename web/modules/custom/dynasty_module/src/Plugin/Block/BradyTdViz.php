<?php

namespace Drupal\dynasty_module\Plugin\Block;

use Drupal\Core\Block\BlockBase;
/**
 * Provides a Block that displays games from this date in history.
 *
 * @Block(
 *   id = "brady_td_viz",
 *   admin_label = @Translation("Brady TD Visualization"),
 *   category = @Translation("Custom"),
 * )
 */
class BradyTdViz extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
      return [
        '#theme' => 'brady_viz',
      ];
    }
  }

