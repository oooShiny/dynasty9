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
  textContainers: {},
  baseParams: {},

  /**
   * Register an iframe element with a category name.
   *
   * @param {string} category - The category name (matches data-video-category)
   * @param {string} iframeId - The iframe element ID
   * @param {string} params - Optional query parameters (e.g., "?links=0&search=0")
   * @param {string} textContainerId - Optional ID of div to populate with video info
   */
  registerIframe: function(category, iframeId, params, textContainerId) {
    const iframe = document.getElementById(iframeId);
    if (iframe) {
      this.iframes[category] = iframe;
      this.baseParams[category] = params || '?links=0&search=0&title=0&controls=[-settings,-chromecast,-airplay]&logo=https://patsdynasty.com/themes/custom/dynasty_tw/images/dynasty-white.png';

      // Register text container if provided
      if (textContainerId) {
        const textContainer = document.getElementById(textContainerId);
        if (textContainer) {
          this.textContainers[category] = textContainer;
        } else {
          console.warn('Text container not found:', textContainerId);
        }
      }

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
   * @param {HTMLElement} clickedElement - Optional element that was clicked (to extract data from)
   */
  switchVideo: function(category, videoId, clickedElement) {
    const iframe = this.iframes[category];
    if (iframe) {
      const params = this.baseParams[category] || '';
      // Add autoplay parameter, handling both ? and & cases
      const separator = params.includes('?') ? '&' : '?';
      const autoplayParam = separator + 'autoplay=1';
      iframe.src = 'https://muse.ai/embed/' + videoId + params + autoplayParam;
      console.log('Switched to video:', videoId, 'for category:', category);

      // Update text container if registered and element provided
      const textContainer = this.textContainers[category];
      if (textContainer && clickedElement) {
        this.updateTextContainer(textContainer, clickedElement);
      }
    } else {
      console.warn('No iframe registered for category:', category);
    }
  },

  /**
   * Update the text container with information from clicked element.
   *
   * @param {HTMLElement} container - The text container element
   * @param {HTMLElement} clickedElement - The clicked element with data attributes
   */
  updateTextContainer: function(container, clickedElement) {
    // Extract all data attributes from the clicked element
    const title = clickedElement.getAttribute('data-title') || clickedElement.textContent || '';
    const game = clickedElement.getAttribute('data-game') || '';
    const opponent = clickedElement.getAttribute('data-opponent') || '';
    const homeAway = clickedElement.getAttribute('data-home-away') || '';
    const date = clickedElement.getAttribute('data-date') || '';

    // Build HTML for the container
    let html = '';
    if (title) {
      html += '<div class="text-2xl font-bold mb-2">' + this.escapeHtml(title) + '</div>';
    }
    if (game) {
      html += '<div class="text-lg">' + this.escapeHtml(game) + '</div>';
    }
    if (opponent) {
      html += '<div>' + this.escapeHtml(opponent) + '</div>';
    }
    if (homeAway) {
      html += '<div class="text-sm">' + this.escapeHtml(homeAway) + '</div>';
    }
    if (date) {
      html += '<div class="text-sm text-gray-600">' + this.escapeHtml(date) + '</div>';
    }

    container.innerHTML = html || '<div class="text-gray-500">Click a video to see details</div>';
  },

  /**
   * Escape HTML to prevent XSS.
   *
   * @param {string} text - Text to escape
   * @return {string} Escaped text
   */
  escapeHtml: function(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  },

  /**
   * Initialize a category with the first available video.
   *
   * @param {string} category - The category name
   * @param {number} retries - Number of retries (internal use)
   */
  initializeWithFirst: function(category, retries) {
    retries = retries || 0;
    const maxRetries = 20; // Try for up to 2 seconds (20 * 100ms)

    // Find the first element with this category
    const firstElement = document.querySelector('[data-video-category="' + category + '"]');

    if (firstElement) {
      const videoId = firstElement.getAttribute('data-video-id');

      if (videoId) {
        // Update iframe without autoplay for initial load
        const iframe = this.iframes[category];
        if (iframe) {
          const params = this.baseParams[category] || '';
          iframe.src = 'https://muse.ai/embed/' + videoId + params;
          console.log('Initialized with first video:', videoId, 'for category:', category);
        }

        // Update text container
        const textContainer = this.textContainers[category];
        if (textContainer) {
          this.updateTextContainer(textContainer, firstElement);
        }

        // Mark as active
        firstElement.classList.add('active');
      } else {
        console.warn('First element found but has no video-id for category:', category);
      }
    } else {
      // Element not found yet - retry if we haven't exceeded max retries
      if (retries < maxRetries) {
        const self = this;
        setTimeout(function() {
          self.initializeWithFirst(category, retries + 1);
        }, 100);
      } else {
        console.warn('No elements found for category after retries:', category);
      }
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

            // Switch the video - pass the clicked element for data extraction
            window.MuseVideoSwitcher.switchVideo(category, videoId, this);

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

          // Switch the video - pass the clicked element for data extraction
          window.MuseVideoSwitcher.switchVideo(category, videoId, this);

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
