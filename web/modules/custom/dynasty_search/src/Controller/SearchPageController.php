<?php

namespace Drupal\dynasty_search\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Controllers for the Game Search and Play Search page shells.
 *
 * Both pages are static markup + empty result containers; all data comes
 * from the /dynasty/search/games and /dynasty/search/plays JSON endpoints
 * and is rendered client-side, so these render arrays are safe to page
 * cache indefinitely.
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
   * Renders the Play Search page.
   */
  public function playsPage(): array {
    return [
      '#theme' => 'dynasty_search_play_page',
      '#attached' => [
        'library' => [
          'dynasty_search/play_search',
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
