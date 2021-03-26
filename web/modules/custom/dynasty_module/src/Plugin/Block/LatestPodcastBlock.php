<?php

namespace Drupal\dynasty_module\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides a Block that displays the most recent podcast episode.
 *
 * @Block(
 *   id = "latest_podcast_block",
 *   admin_label = @Translation("Latest Podcast Block"),
 *   category = @Translation("Dynasty"),
 * )
 */
class LatestPodcastBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    $nid = \Drupal::entityQuery('node')
      ->condition('type','podcast_episode')
      ->sort('created' , 'DESC')
      ->range(0,1)
      ->execute();
    $node = \Drupal::entityTypeManager()->getStorage('node')->load($nid);
    return \Drupal::entityTypeManager()->getViewBuilder('node')->view($node, 'teaser');
  }

}
