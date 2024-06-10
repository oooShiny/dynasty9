<?php

namespace Drupal\dynasty_module\Plugin\Block;

use DateTime;
use Drupal\Core\Block\BlockBase;
use Drupal\node\Entity\Node;

/**
 * Provides a Block that displays top 10 podcast episodes by download count.
 *
 * @Block(
 *   id = "top_10_podcast_block",
 *   admin_label = @Translation("Top 10 Podcast Episodes Block"),
 *   category = @Translation("Dynasty"),
 * )
 */
class Top10PodcastBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {

    $nids = \Drupal::entityQuery('node')
      ->condition('type','podcast_episode')
      ->condition('status', 1)
      ->accessCheck(TRUE)
      ->sort('field_total_downloads' , 'DESC')
      ->range(0, 10)
      ->execute();

    $downloads = [];
    $months = [];
    // Load all podcast nodes.
    foreach (Node::loadMultiple($nids) as $node) {
      $downloads[$node->label()] = $node->get('field_total_downloads')->value;
    }


    return [
      '#theme' => 'top_10_podcast_block',
      '#downloads' => $downloads,
    ];
  }

}
