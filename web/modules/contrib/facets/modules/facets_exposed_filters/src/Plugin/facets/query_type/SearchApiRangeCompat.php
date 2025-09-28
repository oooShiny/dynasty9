<?php

namespace Drupal\facets_exposed_filters\Plugin\facets\query_type;

use Drupal\facets\Plugin\facets\query_type\SearchApiRange;

/**
 * Provides compatibility for search_api_range query type.
 *
 * @FacetsQueryType(
 *   id = "search_api_range",
 *   label = @Translation("Range (Compatibility)"),
 * )
 */
class SearchApiRangeCompat extends SearchApiRange {

  /**
   * {@inheritdoc}
   */
  public function execute() {
    \Drupal::logger('facets_range_debug')->info('SearchApiRangeCompat::execute() called with active items: @items', [
      '@items' => json_encode($this->facet->getActiveItems())
    ]);
    
    return parent::execute();
  }

}