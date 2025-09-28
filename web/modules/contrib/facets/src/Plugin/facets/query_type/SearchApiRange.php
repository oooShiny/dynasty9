<?php

namespace Drupal\facets\Plugin\facets\query_type;

use Drupal\facets\QueryType\QueryTypePluginBase;
use Drupal\facets\Result\Result;
use Drupal\search_api\Query\QueryInterface;

/**
 * Provides support for range facets within the Search API scope.
 *
 * This is the default implementation that works with all backends.
 *
 * @FacetsQueryType(
 *   id = "search_api_range",
 *   label = @Translation("Range"),
 * )
 */
class SearchApiRange extends QueryTypePluginBase {

  /**
   * {@inheritdoc}
   */
  public function execute() {
    $query = $this->query;

    // Only alter the query when there's an actual query object to alter.
    if (!empty($query)) {
      $operator = $this->facet->getQueryOperator();
      $field_identifier = $this->facet->getFieldIdentifier();
      $exclude = $this->facet->getExclude();

      if ($query->getProcessingLevel() === QueryInterface::PROCESSING_FULL) {
        // Set the options for the actual query.
        $options = &$query->getOptions();
        $options['search_api_facets'][$field_identifier] = $this->getFacetOptions();
      }

      // Add the filter to the query if there are active values.
      $active_items = $this->facet->getActiveItems();
      
      \Drupal::logger('facets_range_debug')->info('SearchApiRange: Active items for field @field: @items', [
        '@field' => $field_identifier,
        '@items' => print_r($active_items, TRUE)
      ]);

      if (count($active_items)) {
        $filter = $query->createConditionGroup($operator, ['facet:' . $field_identifier]);
        foreach ($active_items as $value) {
          // Handle array values from exposed filters (e.g., [min, max])
          if (is_array($value) && count($value) === 2) {
            \Drupal::logger('facets_range_debug')->info('SearchApiRange: Processing array range @min to @max for field @field', [
              '@min' => $value[0],
              '@max' => $value[1],
              '@field' => $field_identifier
            ]);
            $filter->addCondition($field_identifier, $value, $exclude ? 'NOT BETWEEN' : 'BETWEEN');
          } else {
            // For single values or string ranges, parse as range if it contains comma or range separator
            \Drupal::logger('facets_range_debug')->info('SearchApiRange: Processing single value @value for field @field', [
              '@value' => $value,
              '@field' => $field_identifier
            ]);
            
            if (is_string($value) && (strpos($value, ',') !== FALSE || strpos($value, ' TO ') !== FALSE)) {
              // Parse range string like "min,max" or "min TO max"
              $range_parts = strpos($value, ' TO ') !== FALSE ? 
                explode(' TO ', $value) : 
                explode(',', $value);
              
              if (count($range_parts) === 2) {
                $min = trim($range_parts[0]);
                $max = trim($range_parts[1]);
                \Drupal::logger('facets_range_debug')->info('SearchApiRange: Parsed string range @min to @max', [
                  '@min' => $min,
                  '@max' => $max
                ]);
                $filter->addCondition($field_identifier, [$min, $max], $exclude ? 'NOT BETWEEN' : 'BETWEEN');
              } else {
                // Single value, use equals
                $filter->addCondition($field_identifier, $value, $exclude ? '!=' : '=');
              }
            } else {
              // Single value, use equals
              $filter->addCondition($field_identifier, $value, $exclude ? '!=' : '=');
            }
          }
        }
        $query->addConditionGroup($filter);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $query_operator = $this->facet->getQueryOperator();

    if (!empty($this->results)) {
      $facet_results = [];
      foreach ($this->results as $result) {
        if ($result['count'] || $query_operator === 'or') {
          $count = $result['count'];
          while (is_array($result['filter'])) {
            $result['filter'] = current($result['filter']);
          }
          $result_filter = trim($result['filter'], '"');
          if ($result_filter === 'NULL' || $result_filter === '') {
            // "Missing" facet items could not be handled in ranges.
            continue;
          }

          $result = new Result($this->facet, $result_filter, $result_filter, $count);
          $facet_results[] = $result;
        }
      }
      $this->facet->setResults($facet_results);
    }
    return $this->facet;
  }

}
