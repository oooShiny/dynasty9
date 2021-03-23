<?php

namespace Drupal\dynasty_module;

use Drupal\node\Entity\Node;

class AddMuseHighlight {
  public static function updateNode($video, $fields, &$context) {
    $results = [];

    $node = Node::load($video['nid']);
    $node->field_muse_video_id->value = $video['muse_id'];

    $results[] = $node->save();
    $context['results'] = $results;
  }
}
