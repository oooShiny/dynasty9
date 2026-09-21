/**
 * @file
 * Podcast Search: fetches /dynasty/search/podcasts once (one row per
 * published podcast_episode node, ~120 total), then does all
 * searching/filtering/sorting/pagination client-side. Replaces the former
 * `podcast_search` Views/Search API page (Solr-backed, same server as the
 * Transcript Search this mirrors -- see
 * \Drupal\dynasty_search\Controller\SearchDataController::podcasts() for
 * why).
 *
 * Unlike Transcript/Play/Game Search, this shows every episode by default
 * (sorted newest-first, same as the retired view) rather than waiting for
 * a search term -- 120 episodes is a normal browsable listing, not
 * something to hide behind a query the way ~60,000 transcript segments or
 * ~135,000 plays are.
 */

(function (Drupal, once) {
  'use strict';

  const DATA_URL = '/dynasty/search/podcasts';
  const RESULTS_PER_PAGE = 20;
  const DEBOUNCE_MS = 300;

  Drupal.behaviors.podcastSearch = {
    attach: function (context) {
      once('podcast-search-init', '#podcast-search-app', context).forEach(function (app) {
        initPodcastSearch(app);
      });
    },
  };

  function initPodcastSearch(app) {
    const input = app.querySelector('#ps-input');
    const searchBtn = app.querySelector('#ps-search-btn');
    const seasonFilter = app.querySelector('#ps-filter-season');
    const typeFilter = app.querySelector('#ps-filter-type');
    const clearFiltersBtn = app.querySelector('#ps-clear-filters');
    const statusEl = app.querySelector('#ps-status');
    const resultsEl = app.querySelector('#ps-results');
    const paginationEl = app.querySelector('#ps-pagination');

    if (!input || !statusEl || !resultsEl || !paginationEl) {
      return;
    }

    let rows = [];
    let currentPage = 0;
    let debounceTimer = null;

    statusEl.textContent = 'Loading episodes…';

    fetch(DATA_URL)
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(function (data) {
        rows = data;
        statusEl.textContent = '';
        populateSeasons();
        bindEvents();
        render(0);
      })
      .catch(function (err) {
        statusEl.textContent = '';
        resultsEl.innerHTML = '<div class="text-red-600 p-4 bg-red-50 rounded-md">Failed to load episodes: ' + escapeHtml(err.message) + '</div>';
      });

    function populateSeasons() {
      if (!seasonFilter) return;
      const seasons = Array.from(new Set(rows.map(function (r) { return r.season; }).filter(Boolean)))
        .sort(function (a, b) { return b - a; });
      seasons.forEach(function (s) {
        const opt = document.createElement('option');
        opt.value = s;
        opt.textContent = s;
        seasonFilter.appendChild(opt);
      });
    }

    function bindEvents() {
      input.addEventListener('keyup', function (e) {
        if (e.key === 'Enter') {
          clearTimeout(debounceTimer);
          render(0);
          return;
        }
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function () { render(0); }, DEBOUNCE_MS);
      });

      if (searchBtn) {
        searchBtn.addEventListener('click', function () { render(0); });
      }

      if (seasonFilter) {
        seasonFilter.addEventListener('change', function () { render(0); });
      }

      if (typeFilter) {
        typeFilter.querySelectorAll('.ps-toggle').forEach(function (btn) {
          btn.addEventListener('click', function () {
            setActiveToggle(btn);
            render(0);
          });
        });
      }

      if (clearFiltersBtn) {
        clearFiltersBtn.addEventListener('click', function () {
          input.value = '';
          if (seasonFilter) seasonFilter.value = '';
          if (typeFilter) setActiveToggle(typeFilter.querySelector('.ps-toggle[data-value=""]'));
          render(0);
        });
      }
    }

    function setActiveToggle(activeBtn) {
      typeFilter.querySelectorAll('.ps-toggle').forEach(function (b) {
        const active = b === activeBtn;
        b.classList.toggle('bg-red-pats', active);
        b.classList.toggle('text-white', active);
        b.classList.toggle('border-red-pats', active);
        b.classList.toggle('bg-white', !active);
        b.classList.toggle('text-gray-700', !active);
        b.classList.toggle('border-gray-300', !active);
      });
    }

    function getFilters() {
      const activeType = typeFilter ? typeFilter.querySelector('.ps-toggle.bg-red-pats') : null;
      return {
        q: input.value.trim(),
        season: seasonFilter ? seasonFilter.value : '',
        type: activeType ? activeType.dataset.value : '',
      };
    }

    function hasActiveFilters(f) {
      return Boolean(f.q || f.season || f.type);
    }

    function matches(r, f, ql) {
      if (ql) {
        const haystack = ((r.title || '') + ' ' + (r.subtitle || '') + ' ' + (r.guest || '') + ' ' + (r.summary || '')).toLowerCase();
        if (haystack.indexOf(ql) === -1) return false;
      }
      if (f.season && String(r.season) !== f.season) return false;
      if (f.type === 'game' && !r.game_url) return false;
      if (f.type === 'non-game' && r.game_url) return false;
      return true;
    }

    function render(page) {
      currentPage = page;
      const f = getFilters();
      if (clearFiltersBtn) clearFiltersBtn.classList.toggle('hidden', !hasActiveFilters(f));

      const ql = f.q.toLowerCase();
      const matched = rows.filter(function (r) { return matches(r, f, ql); });

      statusEl.textContent = 'Showing ' + matched.length.toLocaleString() + ' of ' + rows.length.toLocaleString() + ' episodes';

      const totalPages = Math.max(1, Math.ceil(matched.length / RESULTS_PER_PAGE));
      if (currentPage >= totalPages) currentPage = totalPages - 1;
      if (currentPage < 0) currentPage = 0;
      const start = currentPage * RESULTS_PER_PAGE;
      const pageRows = matched.slice(start, start + RESULTS_PER_PAGE);

      resultsEl.innerHTML = pageRows.length
        ? pageRows.map(function (r) { return renderCard(r, f.q); }).join('')
        : '<div class="text-center p-8 text-gray-500">No episodes match your search.</div>';

      renderPagination(currentPage, totalPages);
    }

    function renderCard(r, query) {
      const coverImg = r.cover_image || 'https://assets.pippa.io/shows/5d23d6cdd5ef084d07ef59b9/1580131386118-8bc942a3e5ac0342ac6ba7eb55da12fe.jpeg';
      const audioId = 'pcast-' + Math.floor(Math.random() * 999999);

      return '\
        <div class="py-5 flex border-b border-gray-300">\
          <div class="w-40 h-40 mr-5 flex-shrink-0 justify-center items-center flex" style="background-image:url(\'' + escapeHtml(coverImg) + '\');background-size:contain;background-repeat:no-repeat;background-position:center;">\
            <audio id="' + audioId + '" src="' + escapeHtml(r.mp3 || '') + '"></audio>\
            <div onclick="skipBackwards(\'' + audioId + '\')" class="my-auto text-white transition-all duration-300 ease-out opacity-0 -mr-6 scale-0 cursor-pointer">\
              <img src="/themes/custom/dynasty_tw/icons/back15-blue.svg" class="w-6 h-6" alt="Back 15 seconds" />\
            </div>\
            <div onclick="playPause(\'' + audioId + '\')" class="cursor-pointer bg-red-pats outline outline-red-pats outline-4 p-4 rounded-full shadow-gray-500 shadow-md text-white w-12">\
              <img src="/themes/custom/dynasty_tw/icons/pod-play.svg" id="play' + audioId + '" class="w-auto h-auto" alt="Play" />\
            </div>\
            <div id="fwd-' + audioId + '" onclick="skipForward(\'' + audioId + '\')" class="my-auto text-white transition-all duration-300 ease-out opacity-0 -ml-6 scale-0 cursor-pointer">\
              <img src="/themes/custom/dynasty_tw/icons/plus15-blue.svg" class="w-6 h-6" alt="Forward 15 seconds" />\
            </div>\
          </div>\
          <div class="flex flex-col justify-between w-3/4 min-w-0">\
            <h2>\
              <a href="' + escapeHtml(r.url) + '" rel="bookmark" class="uppercase text-l">\
                <span class="font-extrabold">' + highlight(r.title, query) + '</span>\
                ' + (r.subtitle ? ' - ' + highlight(r.subtitle, query) : '') + '\
              </a>\
              ' + (r.duration ? '<span class="uppercase font-light text-l"> (' + escapeHtml(r.duration) + ')</span>' : '') + '\
            </h2>\
            ' + (r.season ? '<div class="text-xs text-gray-500 uppercase tracking-wide">Season ' + escapeHtml(String(r.season)) + (r.opponent ? ' &middot; vs ' + escapeHtml(r.opponent) : '') + '</div>' : '') + '\
            <div class="text-sm text-gray-700">' + highlight(r.summary, query) + '</div>\
            <div class="flex gap-2 mt-2">\
              <a href="' + escapeHtml(r.url) + '" class="btn btn-primary text-xs px-3 py-1">Episode Info</a>\
              ' + (r.game_url ? '<a href="' + escapeHtml(r.game_url) + '" class="btn btn-primary text-xs px-3 py-1">Game Info</a>' : '') + '\
            </div>\
          </div>\
        </div>';
    }

    function highlight(text, query) {
      text = text || '';
      if (!query) return escapeHtml(text);
      // Find the match in the raw text (not the escaped output) so a
      // query containing &/</> still matches correctly.
      const idx = text.toLowerCase().indexOf(query.toLowerCase());
      if (idx === -1) return escapeHtml(text);
      return escapeHtml(text.slice(0, idx)) + '<mark class="bg-yellow-200">' + escapeHtml(text.slice(idx, idx + query.length)) + '</mark>' + escapeHtml(text.slice(idx + query.length));
    }

    function renderPagination(page, totalPages) {
      if (totalPages <= 1) {
        paginationEl.innerHTML = '';
        return;
      }
      paginationEl.innerHTML =
        '<button class="px-4 py-2 border border-gray-300 bg-white rounded cursor-pointer disabled:opacity-40" ' + (page <= 0 ? 'disabled' : '') + ' data-page="' + (page - 1) + '">&laquo;&laquo;</button>'
        + '<span class="px-2 py-2">Page ' + (page + 1) + ' of ' + totalPages + '</span>'
        + '<button class="px-4 py-2 border border-gray-300 bg-white rounded cursor-pointer disabled:opacity-40" ' + (page >= totalPages - 1 ? 'disabled' : '') + ' data-page="' + (page + 1) + '">&raquo;&raquo;</button>';

      paginationEl.querySelectorAll('button[data-page]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          if (btn.disabled) return;
          render(parseInt(btn.dataset.page, 10));
          app.scrollIntoView({ behavior: 'smooth' });
        });
      });
    }

    function escapeHtml(str) {
      if (!str) return '';
      const div = document.createElement('div');
      div.textContent = String(str);
      return div.innerHTML;
    }
  }

})(Drupal, once);
