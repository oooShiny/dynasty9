<?php

namespace Drupal\dynasty_search\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

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
   * Renders the merged Play Search page (Player Stats / All Plays modes).
   *
   * Both modes' markup is included in the response (see
   * templates/plays-search-page.html.twig); only the active mode's is live
   * in the initial DOM, the other sits inert inside a <template> tag until
   * the visitor switches to it, so only one of the two (large) datasets is
   * ever fetched unless a visitor actually asks for both.
   */
  public function playsPage(Request $request): array {
    $active_mode = $request->query->get('mode') === 'stats' ? 'stats' : 'plays';

    return [
      '#theme' => 'dynasty_search_plays_page',
      '#active_mode' => $active_mode,
      '#attached' => [
        'library' => [
          'dynasty_search/plays_search',
        ],
      ],
      '#cache' => [
        'contexts' => ['url.query_args:mode'],
        'tags' => [],
        'max-age' => \Drupal\Core\Cache\Cache::PERMANENT,
      ],
    ];
  }

  /**
   * Redirects the old Stat Finder URL to its mode on the merged page.
   */
  public function statsPageRedirect(): RedirectResponse {
    return new RedirectResponse('/search/plays?mode=stats', 301);
  }

  /**
   * Redirects the old Play-by-Play Search URL to the merged page.
   */
  public function playByPlayPageRedirect(): RedirectResponse {
    return new RedirectResponse('/search/plays', 301);
  }

  /**
   * Redirects the retired Views/Search API Transcript Search URL to
   * dynasty_transcript's page (now backed by
   * SearchDataController::transcripts() instead of Solr).
   */
  public function transcriptsPageRedirect(): RedirectResponse {
    return new RedirectResponse('/transcripts/search', 301);
  }

  /**
   * Renders the Podcast Search page at the site's existing `/podcast` URL
   * (the main-nav "Pod" link), replacing the former Views/Search API
   * `podcast_search` view in place -- same URL, so nothing else on the
   * site (menu link, inbound links) needs to change.
   */
  public function podcastsPage(): array {
    return [
      '#theme' => 'dynasty_search_podcast_page',
      '#attached' => [
        'library' => [
          'dynasty_search/podcast_search',
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
