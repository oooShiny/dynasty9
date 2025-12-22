/**
 * @file
 * Advent Calendar JavaScript.
 */

(function (Drupal, drupalSettings) {
  'use strict';

  Drupal.behaviors.adventCalendar = {
    attach: function (context, settings) {
      const doors = context.querySelectorAll('.advent-door');
      const modal = context.querySelector('#advent-modal');
      const modalContent = context.querySelector('#advent-modal-content');

      if (!modal || !modalContent) {
        return;
      }

      // Track opened doors in localStorage
      const openedDoors = JSON.parse(localStorage.getItem('adventDoorsOpened') || '[]');

      // Mark previously opened doors
      openedDoors.forEach(day => {
        const doorWrapper = context.querySelector(`.advent-door-wrapper[data-day="${day}"]`);
        if (doorWrapper) {
          const door = doorWrapper.querySelector('.advent-door');
          const openedIndicator = doorWrapper.querySelector('.opened-indicator');
          if (door && openedIndicator) {
            door.classList.add('opened');
            openedIndicator.classList.remove('hidden');
          }
        }
      });

      doors.forEach(door => {
        door.addEventListener('click', function(e) {
          e.preventDefault();

          const isUnlocked = this.dataset.unlocked === 'true';
          const day = this.closest('.advent-door-wrapper').dataset.day;

          if (!isUnlocked) {
            // Show locked message
            showLockedMessage(modal, modalContent, day);
            return;
          }

          // Fetch door content
          fetchDoorContent(day, modal, modalContent, openedDoors);
        });
      });

      function openModal() {
        if (typeof HSOverlay !== 'undefined') {
          window.HSOverlay.open(modal);
        } else {
          // Fallback: manually show the modal
          modal.classList.remove('hidden');
          modal.classList.add('open');
        }
      }

      function showLockedMessage(modal, modalContent, day) {
        modalContent.innerHTML = `
          <div class="text-center py-8">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-16 w-16 mx-auto text-yellow-500 mb-4" viewBox="0 0 20 20" fill="currentColor">
              <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd" />
            </svg>
            <h3 class="text-2xl font-bold mb-2 text-gray-800 dark:text-white">Door ${day} is Locked</h3>
            <p class="text-gray-600 dark:text-gray-300">Come back on December ${day} to open this door!</p>
          </div>
        `;
        openModal();
      }

      function fetchDoorContent(day, modal, modalContent, openedDoors) {
        // Show loading state
        modalContent.innerHTML = `
          <div class="text-center py-8">
            <svg class="animate-spin h-12 w-12 mx-auto text-blue-pats mb-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <p class="mt-4 text-gray-600 dark:text-gray-300">Opening door ${day}...</p>
          </div>
        `;
        openModal();

        // Fetch content
        fetch(`/advent-calendar/door/${day}`)
          .then(response => {
            if (!response.ok) {
              throw new Error('Failed to load content');
            }
            return response.json();
          })
          .then(data => {
            modalContent.innerHTML = data.content;

            // Mark door as opened
            if (!openedDoors.includes(parseInt(day))) {
              openedDoors.push(parseInt(day));
              localStorage.setItem('adventDoorsOpened', JSON.stringify(openedDoors));

              // Update door appearance
              const doorWrapper = document.querySelector(`.advent-door-wrapper[data-day="${day}"]`);
              if (doorWrapper) {
                const doorElement = doorWrapper.querySelector('.advent-door');
                const openedIndicator = doorWrapper.querySelector('.opened-indicator');
                if (doorElement && openedIndicator) {
                  doorElement.classList.add('opened');
                  openedIndicator.classList.remove('hidden');
                }
              }
            }
          })
          .catch(error => {
            console.error('Error loading door content:', error);
            modalContent.innerHTML = `
              <div class="text-center py-8">
                <div class="text-red-600 dark:text-red-400 text-6xl mb-4">⚠️</div>
                <h3 class="text-2xl font-bold mb-2 text-gray-800 dark:text-white">Oops!</h3>
                <p class="text-gray-600 dark:text-gray-300">Something went wrong loading this door's content.</p>
              </div>
            `;
          });
      }
    }
  };

})(Drupal, drupalSettings);
