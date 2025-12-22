/**
 * @file
 * Video Switcher for Muse.ai players.
 *
 * This script allows switching videos in Muse.ai players by clicking on elements
 * with data-video-id and data-video-category attributes.
 *
 * Usage:
 * 1. Create a player and register it:
 *    const longestPlays = MusePlayer({ ... });
 *    MuseVideoSwitcher.registerPlayer('longest-plays', longestPlays);
 *
 * 2. Add clickable elements with data attributes:
 *    <a data-video-id="UaeHcHT" data-video-category="longest-plays">Video Title</a>
 */

// Create global registry immediately (outside IIFE)
window.MuseVideoSwitcher = window.MuseVideoSwitcher || {
  players: {},

  /**
   * Register a player instance with a category name.
   *
   * @param {string} category - The category name (matches data-video-category)
   * @param {object} player - The MusePlayer instance
   */
  registerPlayer: function(category, player) {
    this.players[category] = player;
    console.log('Registered Muse.ai player:', category);
  },

  /**
   * Switch video for a specific category.
   *
   * @param {string} category - The category name
   * @param {string} videoId - The Muse.ai video ID
   */
  switchVideo: function(category, videoId) {
    const player = this.players[category];
    if (player) {
      // Muse.ai player supports the setVideo method
      if (typeof player.setVideo === 'function') {
        player.setVideo(videoId);
        player.play();
        console.log('Switched to video:', videoId, 'for category:', category);
      } else {
        console.error('Player does not support setVideo method', player);
      }
    } else {
      console.warn('No player registered for category:', category);
    }
  }
};

// Drupal integration (only if Drupal is available)
(function () {
  'use strict';

  // Check if we're in a Drupal environment
  if (typeof Drupal !== 'undefined' && typeof once !== 'undefined') {
    Drupal.behaviors.museVideoSwitcher = {
      attach: function (context, settings) {
        // Find all elements with both data-video-id and data-video-category
        const elements = once('video-switcher', '[data-video-id][data-video-category]', context);

        elements.forEach(function(element) {
          element.addEventListener('click', function(e) {
            e.preventDefault();

            const videoId = this.getAttribute('data-video-id');
            const category = this.getAttribute('data-video-category');

            if (!videoId || !category) {
              console.warn('Missing video-id or category on element:', this);
              return;
            }

            // Switch the video
            window.MuseVideoSwitcher.switchVideo(category, videoId);

            // Update active states for this category
            const categoryElements = document.querySelectorAll('[data-video-category="' + category + '"]');
            categoryElements.forEach(function(el) {
              el.classList.remove('active');
            });

            // Add active class to clicked element
            this.classList.add('active');
          });
        });
      }
    };
  } else {
    // Standalone mode - attach click handlers directly
    document.addEventListener('DOMContentLoaded', function() {
      const elements = document.querySelectorAll('[data-video-id][data-video-category]');

      elements.forEach(function(element) {
        element.addEventListener('click', function(e) {
          e.preventDefault();

          const videoId = this.getAttribute('data-video-id');
          const category = this.getAttribute('data-video-category');

          if (!videoId || !category) {
            console.warn('Missing video-id or category on element:', this);
            return;
          }

          // Switch the video
          window.MuseVideoSwitcher.switchVideo(category, videoId);

          // Update active states for this category
          const categoryElements = document.querySelectorAll('[data-video-category="' + category + '"]');
          categoryElements.forEach(function(el) {
            el.classList.remove('active');
          });

          // Add active class to clicked element
          this.classList.add('active');
        });
      });
    });
  }
})();
