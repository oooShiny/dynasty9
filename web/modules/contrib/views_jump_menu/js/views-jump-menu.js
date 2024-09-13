/**
 * @file
 * Attaches the behaviors for the Views-Jump-Menu module.
 */

(function (Drupal) {
  'use strict';

  Drupal.behaviors.viewsJumpMenu = {
    attach: function (context, settings) {
      context.querySelectorAll('.js-viewsJumpMenu').forEach(function (element) {
        element.addEventListener('change', function () {
          const currentJumpMenuId = this.getAttribute('id');
          const destination = element.selectedOptions[0].dataset.url;
          if (!destination) {
            return;
          }

          if (currentJumpMenuId && settings.viewsJumpMenu[currentJumpMenuId]['new_window']) {
            window.open(destination, '_blank', 'noopener');
            this.selectedIndex = '';
          }
          else {
            window.location = destination;
          }
        });
      });
    }
  };
})(Drupal);
