/**
 * @file
 * Video Switcher for Muse.ai iframes.
 *
 * This script allows switching videos in Muse.ai iframe embeds by clicking on elements
 * with data-video-id and data-video-category attributes.
 *
 * Usage:
 * 1. Create an iframe with an ID and register it:
 *    <iframe id="longest-plays-player" src="https://muse.ai/embed/VIDEO_ID?..."></iframe>
 *    MuseVideoSwitcher.registerIframe('longest-plays', 'longest-plays-player');
 *
 * 2. Add clickable elements with data attributes:
 *    <a data-video-id="UaeHcHT" data-video-category="longest-plays">Video Title</a>
 */

// Create global registry immediately (outside IIFE)
window.MuseVideoSwitcher = window.MuseVideoSwitcher || {
  iframes: {},
  baseParams: {},

  /**
   * Register an iframe element with a category name.
   *
   * @param {string} category - The category name (matches data-video-category)
   * @param {string} iframeId - The iframe element ID
   * @param {string} params - Optional query parameters (e.g., "?links=0&search=0")
   */
  registerIframe: function(category, iframeId, params) {
    const iframe = document.getElementById(iframeId);
    if (iframe) {
      this.iframes[category] = iframe;
      this.baseParams[category] = params || '?links=0&search=0&title=0&controls=[-settings,-chromecast,-airplay]&logo=https://patsdynasty.com/themes/custom/dynasty_tw/images/dynasty-white.png';
      console.log('Registered Muse.ai iframe:', category);
    } else {
      console.error('Iframe not found:', iframeId);
    }
  },

  /**
   * Legacy support: Register a player instance (redirects to registerIframe)
   * @deprecated Use registerIframe instead
   */
  registerPlayer: function(category, elementOrId) {
    console.warn('registerPlayer is deprecated. Use registerIframe instead.');
    if (typeof elementOrId === 'string') {
      this.registerIframe(category, elementOrId);
    } else if (elementOrId && elementOrId.id) {
      this.registerIframe(category, elementOrId.id);
    }
  },

  /**
   * Switch video for a specific category.
   *
   * @param {string} category - The category name
   * @param {string} videoId - The Muse.ai video ID
   */
  switchVideo: function(category, videoId) {
    const iframe = this.iframes[category];
    if (iframe) {
      const params = this.baseParams[category] || '';
      // Add autoplay parameter, handling both ? and & cases
      const separator = params.includes('?') ? '&' : '?';
      const autoplayParam = separator + 'autoplay=1';
      iframe.src = 'https://muse.ai/embed/' + videoId + params + autoplayParam;
      console.log('Switched to video:', videoId, 'for category:', category);
    } else {
      console.warn('No iframe registered for category:', category);
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
