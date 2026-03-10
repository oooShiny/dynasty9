(function () {
  'use strict';

  const placeholder = document.getElementById('random-highlight-placeholder');
  if (!placeholder) return;

  const src = placeholder.dataset.src;
  if (!src) return;

  fetch(src)
    .then(function (response) {
      if (!response.ok) return;
      return response.text();
    })
    .then(function (html) {
      if (html) {
        placeholder.outerHTML = html;
      }
    });
})();
