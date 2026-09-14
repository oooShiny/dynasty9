<?php

namespace Drupal\dynasty_search\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Controllers for the Game Search and Highlight Search page shells.
 *
 * Both pages are static markup + empty result containers; all data comes
 * from the /dynasty/search/games and /dynasty/search/highlights JSON
 * endpoints and is rendered client-side, so these render arrays are safe to
 * page cache indefinitely.
 */
class SearchPageController extends ControllerBase {

  /**
   * Renders the Game Search page.
   */
  public function gamesPage(): array {
    return [
      '#theme' => 'dynasty_search_game_page',
      '#attached' => [
        'library' => [
          'dynasty_search/game_search',
        ],
      ],
      '#cache' => [
        'contexts' => [],
        'tags' => [],
        'max-age' => \Drupal\Core\Cache\Cache::PERMANENT,
      ],
    ];
  }

  /**
   * Renders the Highlight Search page.
   */
  public function highlightsPage(): array {
    return [
      '#theme' => 'dynasty_search_highlight_page',
      '#attached' => [
        'library' => [
          'dynasty_search/highlight_search',
        ],
      ],
      '#cache' => [
        'contexts' => [],
        'tags' => [],
        'max-age' => \Drupal\Core\Cache\Cache::PERMANENT,
      ],
    ];
  }

  /**
   * Renders the Stat Finder page.
   */
  public function statsPage(): array {
    return [
      '#theme' => 'dynasty_search_stat_page',
      '#attached' => [
        'library' => [
          'dynasty_search/stat_search',
        ],
      ],
      '#cache' => [
        'contexts' => [],
        'tags' => [],
        'max-age' => \Drupal\Core\Cache\Cache::PERMANENT,
      ],
    ];
  }

  /**
   * Renders the Play-by-Play Search page.
   */
  public function playByPlayPage(): array {
    return [
      '#theme' => 'dynasty_search_pbp_page',
      '#attached' => [
        'library' => [
          'dynasty_search/pbp_search',
        ],
      ],
      '#cache' => [
        'contexts' => [],
        'tags' => [],
        'max-age' => \Drupal\Core\Cache\Cache::PERMANENT,
      ],
    ];
  }

}
