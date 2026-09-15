/**
 * @file
 * Mode toggle for the merged Play Search page (/search/plays).
 *
 * The page ships with only the active mode's markup live in the DOM; the
 * other mode's identical markup (from pbp-search-page.html.twig /
 * stat-search-page.html.twig, included as-is -- see plays-search-page.html.twig)
 * sits inert inside a <template> tag so Stat Finder's and Play-by-Play
 * Search's own Drupal.behaviors (stat-search.js, pbp-search.js -- both
 * unmodified) don't fetch their dataset until a visitor actually switches to
 * that mode. Once hydrated out of its <template>, a mode's container stays
 * in the live DOM (just hidden/shown), so its own `once()` guard prevents
 * re-fetching on subsequent toggles.
 */

(function (Drupal) {
  'use strict';

  Drupal.behaviors.playsSearchToggle = {
    attach: function (context) {
      const toggle = context.querySelector
        ? context.querySelector('#plays-mode-toggle')
        : null;
      if (!toggle || toggle.dataset.playsToggleInit) {
        return;
      }
      toggle.dataset.playsToggleInit = '1';

      const buttons = toggle.querySelectorAll('[data-mode]');
      const mounts = {
        plays: document.getElementById('plays-mode-mount-plays'),
        stats: document.getElementById('plays-mode-mount-stats'),
      };

      function setActiveButton(mode) {
        buttons.forEach(function (btn) {
          const active = btn.dataset.mode === mode;
          btn.classList.toggle('bg-red-pats', active);
          btn.classList.toggle('text-white', active);
          btn.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
      }

      function activate(mode) {
        Object.keys(mounts).forEach(function (key) {
          const mount = mounts[key];
          if (!mount) {
            return;
          }
          if (key === mode) {
            const tpl = mount.querySelector('template');
            if (tpl) {
              mount.appendChild(tpl.content.cloneNode(true));
              tpl.remove();
              Drupal.attachBehaviors(mount);
            }
            mount.classList.remove('hidden');
          }
          else {
            mount.classList.add('hidden');
          }
        });

        setActiveButton(mode);

        // No URL sync here: both stat-search.js and pbp-search.js do their
        // own history.replaceState() for their filter state, wholesale
        // rebuilding the querystring from only their own known keys -- that
        // would immediately clobber a `mode` param we set. The server-side
        // `?mode=stats` link (used e.g. by the initial page load) still
        // works; toggling after that is purely client-side.
      }

      buttons.forEach(function (btn) {
        btn.addEventListener('click', function () {
          activate(btn.dataset.mode);
        });
      });

      setActiveButton(toggle.dataset.activeMode);
    }
  };
})(Drupal);
