/**
 * @file
 * JavaScript file for the gin_gutenberg module.
 */

import '../sass/gin_gutenberg.scss';

if (document.body.classList.contains('gutenberg--enabled')) {
  const metaSidebarTrigger = document.querySelector('.meta-sidebar__trigger');
  if (metaSidebarTrigger) {
    metaSidebarTrigger.addEventListener('click', function () {
      const interfacePinnedItemsButton = document.querySelector(
        '.edit-post-header__settings .interface-pinned-items .components-button',
      );
      if (interfacePinnedItemsButton) {
        interfacePinnedItemsButton.click();
      }
    });
  }
}
