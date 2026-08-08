<?php

namespace Drupal\dynasty_module\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides a Block that displays a link to the previous game.
 *
 * @Block(
 *   id = "prev_game",
 *   admin_label = @Translation("Previous Game Block"),
 *   category = @Translation("Dynasty"),
 *   context_definitions = {
 *     "node" = @ContextDefinition("entity:node", label = @Translation("Node"))
 *   }
 * )
 */
class PrevGameBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    // Get game date from current node.
    $node = $this->getContextValue('node');
    $date = $node->get('field_date')->value;

    // Find the previous game by querying only games before the current date.
    $prev_nids = \Drupal::entityQuery('node')
      ->accessCheck(TRUE)
      ->condition('type', 'game')
      ->condition('field_date', $date, '<')
      ->sort('field_date', 'DESC')
      ->range(0, 1)
      ->execute();

    $previous = NULL;
    if (!empty($prev_nids)) {
      $prev_nid = reset($prev_nids);
      $previous = \Drupal::service('path_alias.manager')->getAliasByPath('/node/' . $prev_nid);
    }

    return [
      '#theme' => 'prev_block',
      '#previous' => $previous,
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
