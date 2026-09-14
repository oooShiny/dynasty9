<?php

namespace Drupal\dynasty_search\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;

/**
 * Embeds the shared client-side Highlight Search app on Player node pages.
 *
 * Reuses the exact same dynasty_search/highlight_search library, JS, and
 * markup as the standalone /search/highlights page (see
 * _highlight-search-app.html.twig), scoped to the current player via a
 * data-player-nid attribute that js/highlight-search.js reads to filter
 * the dataset down to just this player's highlights.
 *
 * Replaces the old Solr/Search API-backed player_page_highlight_search
 * view + its 8 facets.
 *
 * @Block(
 *   id = "player_highlight_search_block",
 *   admin_label = @Translation("Player Highlight Search"),
 *   category = @Translation("Dynasty"),
 *   context_definitions = {
 *     "node" = @ContextDefinition("entity:node", label = @Translation("Node"))
 *   }
 * )
 */
class PlayerHighlightSearchBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $node = $this->getContextValue('node');

    // Defense-in-depth only: display is actually scoped to Player node
    // pages by the block placement's entity_bundle:node visibility
    // condition (the same pattern the 5 blocks this replaces used), not by
    // this check.
    if (!$node || $node->bundle() !== 'player') {
      return [];
    }

    return [
      '#theme' => 'dynasty_search_player_highlight_block',
      '#player_nid' => (int) $node->id(),
      '#attached' => [
        'library' => [
          'dynasty_search/highlight_search',
        ],
      ],
      '#cache' => [
        'contexts' => ['route'],
        'tags' => ['node_list:highlight'],
        'max-age' => Cache::PERMANENT,
      ],
    ];
  }

}
