<?php

namespace Drupal\dynasty_module\Plugin\Block;

use Drupal\Core\Block\BlockBase;

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
      $next = '/show-notes/' . $next_nid;
    }

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
      $previous = '/show-notes/' . $prev_nid;
    }

    return [
      '#theme' => 'prev_next_block',
      '#previous' => $previous,
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
