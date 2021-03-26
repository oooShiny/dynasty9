<?php

namespace Drupal\dynasty_module\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\node\Entity\Node;

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
    $node = Node::load(end($nid));

    $link_array = parse_url($node->get('field_mp3')->value);
    $path_array = explode('/', $link_array['path']);
    $mp3 = $path_array[6];
    $episode = [
      'title' => $node->label(),
      'id' => $node->id(),
      'mp3' => $mp3,
      'duration' => $node->get('field_duration')->value,
      'description' => $node->get('body')->value
    ];

    return [
      '#theme' => 'latest_podcast_block',
      '#episode' => $episode
    ];
  }

}
