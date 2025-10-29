<?php

namespace Drupal\dynasty_module\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\node\Entity\Node;

/**
 * Provides a Block that displays buttons to go to the prev/next show notes.
 *
 * @Block(
 *   id = "prev_next_show_notes",
 *   admin_label = @Translation("Previous/Next Show Notes Block"),
 *   category = @Translation("Dynasty"),
 *   context_definitions = {
 *     "node" = @ContextDefinition("entity:node", label = @Translation("Node"))
 *   }
 * )
 */
class PrevNextGameBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    // Get game date from current node.
    $node = $this->getContextValue('node');
    $date = $node->get('field_date')->value;

    // Get all game nodes.
    $nids = \Drupal::entityQuery('node')->accessCheck(TRUE)->condition('type','game')->execute();
    $nodes = Node::loadMultiple($nids);

    $games = [];
    foreach ($nodes as $n) {
      $games[$n->get('field_date')->value] = $n->id();
    }
    // Find the next game by date.
    ksort($games);
    $nid = $this->get_adjacent_game($games, $date);
    if ($nid) {
      $next_nid = $games[$nid];
      $next = '/show-notes/' . $next_nid;
    }
    else {
      $next = NULL;
    }
    // Find the previous game by date.
    krsort($games);
    $nid = $this->get_adjacent_game($games, $date);
    if ($nid) {
      $previous_nid = $games[$nid];
      $previous = '/show-notes/' . $previous_nid;
    }
    else {
      $previous = NULL;
    }
    // Display both as links.
    return [
      '#theme' => 'prev_next_block',
      '#previous' => $previous,
      '#next' => $next
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getContextMapping() {
    $mapping = parent::getContextMapping();
    // By default, get the node from the URL.
    return $mapping ?: ['node' => '@node.node_route_context:node'];
  }

  private function get_adjacent_game($array, $key)  {
    $keys = array_keys($array);
    $index = array_search($key, $keys);
    if ( count($array) <= $index + 1 ) {
      return;
    } else {
      return $keys[$index + 1];
    }
  }
}
