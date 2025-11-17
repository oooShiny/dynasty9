<?php

namespace Drupal\facets_range_widget\Plugin\better_exposed_filters\filter;

use Drupal\better_exposed_filters\Plugin\better_exposed_filters\filter\Sliders;
use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormStateInterface;
use Drupal\facets_exposed_filters\Plugin\views\filter\FacetsFilter;

/**
 * Exposed Range Sliders widget implementation.
 *
 * @BetterExposedFiltersFilterWidget(
 *   id = "facets_range_slider",
 *   label = @Translation("Facets Range Slider"),
 * )
 */
class ExposedRangeSliderWidget extends Sliders {

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(mixed $handler = NULL, array $options = []): bool {
    return ($handler instanceof FacetsFilter) && !empty($handler->options['facet']['processor_configs']['exposed_range_slider']['settings']);
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return parent::defaultConfiguration() + [
      'enable_tooltips' => TRUE,
      'tooltips_value_prefix' => '',
      'tooltips_value_suffix' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function exposedFormAlter(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\facets_exposed_filters\Plugin\views\filter\FacetsFilter $filter */
    $filter = $this->handler;
    $filter_id = $filter->options['expose']['identifier'];

    if (empty($form[$filter_id]['#type'])) {
      return;
    }

    $processor_settings = $filter->options['facet']['processor_configs']['exposed_range_slider']['settings'];

    // Get facet results to determine min/max values
    $facet_results = $filter->facet_results;
    /** @var \Drupal\facets\Result\Result|null $min_result */
    $min_result = $facet_results[0] ?? NULL;
    /** @var \Drupal\facets\Result\Result|null $max_result */
    $max_result = $facet_results[1] ?? NULL;

    $min_result_value = $min_result?->getRawValue() ?? 0;
    $max_result_value = $max_result?->getRawValue() ?? 0;

    // Override configuration with facet values
    $this->configuration['min'] = $min_result_value;
    $this->configuration['max'] = $max_result_value;
    $this->configuration['step'] = $processor_settings['step'] ?? 1;
    $this->configuration['enable_tooltips'] = TRUE;

    // Convert the multi-select to a range input structure that BEF can handle
    if ($form[$filter_id]['#type'] === 'select') {
      /** @var array $exposed_input */
      $exposed_input = $this->view->getExposedInput()[$filter_id] ?? [];
      $exposed_input_min = $exposed_input['min'] ?? $min_result_value;
      $exposed_input_max = $exposed_input['max'] ?? $max_result_value;

      // Create a structure with collapsible details wrapper
      $form[$filter_id] = [
        '#type' => 'details',
        '#title' => $form[$filter_id]['#title'] ?? $this->t('Score Differential'),
        '#open' => FALSE,
        '#tree' => TRUE,
        'min' => [
          '#type' => 'textfield',
          '#title' => $this->t('Min'),
          '#default_value' => $exposed_input_min,
          '#size' => 10,
          '#attributes' => ['class' => ['bef-slider-min']],
        ],
        'max' => [
          '#type' => 'textfield',
          '#title' => $this->t('Max'),
          '#default_value' => $exposed_input_max,
          '#size' => 10,
          '#attributes' => ['class' => ['bef-slider-max']],
        ],
        '#attached' => [
          'library' => [
            'better_exposed_filters/sliders',
            'dynasty_tw/facets_range_slider_tooltips'
          ],
          'drupalSettings' => [
            'better_exposed_filters' => [
              'slider' => TRUE,
              'slider_options' => [
                $filter_id => [
                  'min' => $min_result_value,
                  'max' => $max_result_value,
                  'step' => $processor_settings['step'] ?? 1,
                  'animate' => $this->configuration['animate'] ?? 0,
                  'orientation' => $this->configuration['orientation'] ?? 'horizontal',
                  'id' => Html::getUniqueId($filter_id),
                  'dataSelector' => Html::getId($filter_id),
                  'viewId' => $form['#id'] ?? 'views-exposed-form',
                  'tooltips' => TRUE,
                ],
              ],
            ],
          ],
        ],
      ];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function exposedFormSubmit(&$form, FormStateInterface $form_state, &$exclude) {
    parent::exposedFormSubmit($form, $form_state, $exclude);

    /** @var \Drupal\facets_exposed_filters\Plugin\views\filter\FacetsFilter $filter */
    $filter = $this->handler;
    $filter_id = $filter->options['expose']['identifier'];

    $values = $form_state->getValue($filter_id);

    if (!empty($values) && isset($values['min']) && isset($values['max'])) {
      $min = (float) $values['min'];
      $max = (float) $values['max'];

      // Transform for the exposed input that gets passed to the view
      // Try setting it in the exposed input directly
      $exposed_input = $form_state->getValue('');
      $exposed_input[$filter_id] = [$min, $max];
      $form_state->setValue('', $exposed_input);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function exposedFormValidate(&$form, FormStateInterface $form_state) {
    parent::exposedFormValidate($form, $form_state);

    // Transform the min/max values into the format expected by the facets range query type
    /** @var \Drupal\facets_exposed_filters\Plugin\views\filter\FacetsFilter $filter */
    $filter = $this->handler;
    $filter_id = $filter->options['expose']['identifier'];

    $values = $form_state->getValue($filter_id);


    if (!empty($values) && isset($values['min']) && isset($values['max'])) {
      $min = (float) $values['min'];
      $max = (float) $values['max'];

      // If both values are the same as the facet min/max, don't filter
      $facet_results = $filter->facet_results ?? [];
      if (count($facet_results) >= 2) {
        $facet_min = (float) $facet_results[0]->getRawValue();
        $facet_max = (float) end($facet_results)->getRawValue();

        if ($min == $facet_min && $max == $facet_max) {
          // Clear the filter - no filtering needed
          $form_state->setValue($filter_id, NULL);
          return;
        }
      }

      // Set the range value in the format expected by Search API BETWEEN operator
      // This should be an array with [min, max] values
      $range_value = [$min, $max];
      $form_state->setValue($filter_id, $range_value);
    } else {
      \Drupal::logger('facets_range_debug')->info('No valid min/max values found');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);

    // Hide parent's widget fields as we can't get the exposed form's
    // configs in the facet processors for the 'step'
    // (see code of the exposed_range_slider processor) and
    // support of other configs has not been implemented in the widget.
    $field_ids = [
      'min',
      'max',
      'step',
      'animate',
      'animate_ms',
      'orientation',
    ];
    foreach ($field_ids as $field_id) {
      $form[$field_id]['#access'] = FALSE;
    }

    return $form;
  }

}
