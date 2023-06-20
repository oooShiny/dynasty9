<?php

namespace Drupal\dynasty_module;


use Drupal\node\Entity\Node;

class AddYoutubeHighlightToGame {
  public static function updateNode($nodes) {
    $results = [];
    $node = Node::load($nodes['highlight']);
    $node->field_game->target_id = $nodes['game'];
    $results[] = $node->save();
    $context['results'] = $results;
  }
}
