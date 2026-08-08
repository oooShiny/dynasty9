<?php

namespace Drupal\dynasty_module\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides a Block that displays a link to the next game.
 *
 * @Block(
 *   id = "next_game",
 *   admin_label = @Translation("Next Game Block"),
 *   category = @Translation("Dynasty"),
 *   context_definitions = {
 *     "node" = @ContextDefinition("entity:node", label = @Translation("Node"))
 *   }
 * )
 */
class NextGameBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    // Get game date from current node.
    $node = $this->getContextValue('node');
    $date = $node->get('field_date')->value;

    // Find the next game by querying only games after the current date.
    $next_nids = \Drupal::entityQuery('node')
      ->accessCheck(TRUE)
      ->condition('type', 'game')
      ->condition('field_date', $date, '>')
      ->sort('field_date', 'ASC')
      ->range(0, 1)
      ->execute();

    $next = NULL;
    if (!empty($next_nids)) {
      $next_nid = reset($next_nids);
      $next = \Drupal::service('path_alias.manager')->getAliasByPath('/node/' . $next_nid);
    }

    return [
      '#theme' => 'next_block',
      '#next' => $next,
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

}
