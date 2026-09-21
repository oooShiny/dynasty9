<?php

namespace Drupal\dynasty_query\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Cache\Cache;

/**
 * Renders the Query Builder page shell.
 *
 * Static markup + an empty results container, same pattern as
 * dynasty_search's page controllers -- all data comes from
 * /dynasty/query/schema, /dynasty/query/options, and /dynasty/query/run,
 * so this is safe to page-cache indefinitely.
 */
class QueryPageController extends ControllerBase {

  public function page(): array {
    return [
      '#theme' => 'dynasty_query_page',
      '#attached' => [
        'library' => [
          'dynasty_query/query_builder',
        ],
      ],
      '#cache' => [
        'contexts' => [],
        'tags' => [],
        'max-age' => Cache::PERMANENT,
      ],
    ];
  }

}
