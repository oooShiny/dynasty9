/**
 * @file
 * Adds tooltips to facets range sliders.
 */

(function ($, Drupal, once) {
  'use strict';

  Drupal.behaviors.facetsRangeSliderTooltips = {
    attach: function (context, settings) {
      // Wait for BEF sliders to be initialized first
      setTimeout(function() {
        $(once('facets-tooltips', '.bef-slider', context)).each(function() {
          var slider = this;
          
          if (slider.noUiSlider) {
            // Add tooltips to existing slider
            slider.noUiSlider.updateOptions({
              tooltips: [
                {
                  to: function(value) {
                    return Math.round(value);
                  }
                },
                {
                  to: function(value) {
                    return Math.round(value);
                  }
                }
              ]
            });
          }
        });
      }, 100);
    }
  };

})(jQuery, Drupal, once);