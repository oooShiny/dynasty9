/******/ (() => { // webpackBootstrap
/******/ 	"use strict";
var __webpack_exports__ = {};

;// CONCATENATED MODULE: external ["wp","interactivity"]
const external_wp_interactivity_namespaceObject = window["wp"]["interactivity"];
;// CONCATENATED MODULE: ./packages/block-library/build-module/query/view.js
/**
 * WordPress dependencies
 */

const isValidLink = ref => ref && ref instanceof window.HTMLAnchorElement && ref.href && (!ref.target || ref.target === '_self') && ref.origin === window.location.origin;
const isValidEvent = event => event.button === 0 &&
// left clicks only
!event.metaKey &&
// open in new tab (mac)
!event.ctrlKey &&
// open in new tab (windows)
!event.altKey &&
// download
!event.shiftKey && !event.defaultPrevented;
(0,external_wp_interactivity_namespaceObject.store)({
  selectors: {
    core: {
      query: {
        startAnimation: ({
          context
        }) => context.core.query.animation === 'start',
        finishAnimation: ({
          context
        }) => context.core.query.animation === 'finish'
      }
    }
  },
  actions: {
    core: {
      query: {
        navigate: async ({
          event,
          ref,
          context
        }) => {
          const isDisabled = ref.closest('[data-wp-navigation-id]')?.dataset.wpNavigationDisabled;
          if (isValidLink(ref) && isValidEvent(event) && !isDisabled) {
            event.preventDefault();
            const id = ref.closest('[data-wp-navigation-id]').dataset.wpNavigationId;

            // Don't announce the navigation immediately, wait 300 ms.
            const timeout = setTimeout(() => {
              context.core.query.message = context.core.query.loadingText;
              context.core.query.animation = 'start';
            }, 400);
            await (0,external_wp_interactivity_namespaceObject.navigate)(ref.href);

            // Dismiss loading message if it hasn't been added yet.
            clearTimeout(timeout);

            // Announce that the page has been loaded. If the message is the
            // same, we use a no-break space similar to the @wordpress/a11y
            // package: https://github.com/WordPress/gutenberg/blob/c395242b8e6ee20f8b06c199e4fc2920d7018af1/packages/a11y/src/filter-message.js#L20-L26
            context.core.query.message = context.core.query.loadedText + (context.core.query.message === context.core.query.loadedText ? '\u00A0' : '');
            context.core.query.animation = 'finish';
            context.core.query.url = ref.href;

            // Focus the first anchor of the Query block.
            const firstAnchor = `[data-wp-navigation-id=${id}] .wp-block-post-template a[href]`;
            document.querySelector(firstAnchor)?.focus();
          }
        },
        prefetch: async ({
          ref
        }) => {
          const isDisabled = ref.closest('[data-wp-navigation-id]')?.dataset.wpNavigationDisabled;
          if (isValidLink(ref) && !isDisabled) {
            await (0,external_wp_interactivity_namespaceObject.prefetch)(ref.href);
          }
        }
      }
    }
  },
  effects: {
    core: {
      query: {
        prefetch: async ({
          ref,
          context
        }) => {
          if (context.core.query.url && isValidLink(ref)) {
            await (0,external_wp_interactivity_namespaceObject.prefetch)(ref.href);
          }
        }
      }
    }
  }
});

/******/ })()
;