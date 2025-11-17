<?php

namespace Drupal\facets_range_widget\Plugin\facets\processor;

use Drupal\Core\Form\FormStateInterface;
use Drupal\facets\Annotation\FacetsProcessor;
use Drupal\facets\FacetInterface;
use Drupal\facets\Processor\PostQueryProcessorInterface;
use Drupal\facets\Processor\ProcessorPluginBase;
use Drupal\facets\Result\Result;
use Drupal\facets\Result\ResultInterface;

/**
 * Provides a processor that adds all range values between a min and max range.
 *
 * @FacetsProcessor(
 *   id = "exposed_range_slider",
 *   label = @Translation("Exposed Filter Facet Range Slider"),
 *   description = @Translation("Add results for all the steps between min and max range."),
 *   stages = {
 *     "post_query" = 60,
 *   }
 * )
 */
class ExposedRangeSliderProcessor extends ProcessorPluginBase implements PostQueryProcessorInterface {

  /**
   * {@inheritdoc}
   */
  public function postQuery(FacetInterface $facet): void {
    $results = $facet->getResults();

    if (count($results) === 0) {
      return;
    }

    uasort($results, function (ResultInterface $a, ResultInterface $b) {
      if ($a->getRawValue() === $b->getRawValue()) {
        return 0;
      }
      return $a->getRawValue() < $b->getRawValue() ? -1 : 1;
    });

    $step = $this->configuration['step'];

    // Round displayed values to step's precision.
    $precision = strlen(substr(strrchr($step, "."), 1));

    $minResult = reset($results);
    $minValue = number_format($this->floorPlus((float) $minResult->getRawValue(), $precision), $precision, '.', '');

    $maxResult = end($results);
    $maxValue = number_format($this->ceilPlus((float) $maxResult->getRawValue(), $precision), $precision, '.', '');

    // Overwrite the current facet values with the generated results.
    $facet->setResults([
      new Result($facet, $minValue, $minValue, $minResult->getCount()),
      new Result($facet, $maxValue, $maxValue, $maxResult->getCount()),
    ]);
  }

  /**
   * Custom ceil function with support for precision rounding.
   *
   * @param float $value
   *   The number to be rounded down.
   * @param int|null $precision
   *   The optional precision for the rounding.
   *
   * @return float
   *   The resulted rounded number.
   */
  private function ceilPlus(float $value, ?int $precision = NULL): float {
    if (NULL === $precision) {
      return (float) ceil($value);
    }
    if ($precision < 0) {
      throw new \RuntimeException('Invalid precision');
    }
    $reg = $value + 0.5 / (10 ** $precision);
    return round($reg, $precision, $reg > 0 ? PHP_ROUND_HALF_DOWN : PHP_ROUND_HALF_UP);
  }

  /**
   * Custom floor function with support for precision rounding.
   *
   * @param float $value
   *   The number to be rounded up.
   * @param int|null $precision
   *   The optional precision for the rounding.
   *
   * @return float
   *   The resulted rounded number.
   */
  private function floorPlus(float $value, ?int $precision = NULL): float {
    if (NULL === $precision) {
      return (float) floor($value);
    }
    if ($precision < 0) {
      throw new \RuntimeException('Invalid precision');
    }
    $reg = $value - 0.5 / (10 ** $precision);
    return round($reg, $precision, $reg > 0 ? PHP_ROUND_HALF_UP : PHP_ROUND_HALF_DOWN);
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state, FacetInterface $facet) {
    $config = $this->getConfiguration();

    $build = [];
    // We are using config form in the processor as we can't get settings from
    // the exposed form widget from a facet (without widget) there.
    $build['step'] = [
      '#type' => 'number',
      '#step' => 0.001,
      '#title' => $this->t('Slider Step'),
      '#default_value' => $config['step'],
      '#size' => 2,
    ];

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'step' => 1,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getQueryType() {
    return 'range';
  }

}
