/**
 * @file
 * JavaScript file for the gin_gutenberg module.
 */

import '../sass/gin_gutenberg.scss';

if (document.body.classList.contains('gutenberg--enabled')) {
  document.querySelector('.meta-sidebar__trigger').addEventListener('click', function () {
    document.querySelector('.edit-post-header__settings .interface-pinned-items .components-button').click();
  });
}
