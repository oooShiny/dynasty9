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
      ->accessCheck(TRUE)
      ->sort('created' , 'DESC')
      ->range(0,1)
      ->execute();
    $node = Node::load(end($nid));

    $link_array = parse_url($node->get('field_mp3')->value);
    $path_array = explode('/', $link_array['path']);
    $mp3 = $path_array[6];

    $episode = [
      'title' => $node->label(),
      'subtitle' => $node->get('field_subtitle')->value,
      'id' => $node->id(),
      'mp3' => $mp3,
      'mp3_url' => $node->get('field_mp3')->value,
      'duration' => $node->get('field_duration')->value,
      'description' => $node->get('body')->value,
      'ep_art' => $node->get('field_episode_cover_image')->value
    ];

    return [
      '#theme' => 'latest_podcast_block',
      '#episode' => $episode
    ];
  }

}
