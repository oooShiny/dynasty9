<?php

namespace Drupal\dynasty_module;

use Drupal\node\Entity\Node;

class AddMuseFullGame {
  public static function updateNode($video, $fields, &$context) {
    $results = [];

    $node = Node::load($video['nid']);
    $node->field_game_video->appendItem('https://skiv.com/e/' . $video['muse_id']);

    $results[] = $node->save();
    $context['results'] = $results;
  }
}
