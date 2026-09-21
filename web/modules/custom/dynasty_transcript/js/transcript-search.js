/**
 * @file
 * Transcript search: fetches /dynasty/search/transcripts once (one row per
 * transcript segment, ~60,000 across ~120 episodes), then does all
 * searching/filtering/pagination client-side. Replaces the former
 * Solr-backed version of this widget, which proxied every keystroke to an
 * external Solr server via /api/transcript-search (see
 * \Drupal\dynasty_search\Controller\SearchDataController::transcripts()
 * for why that endpoint -- and this rewrite -- exist instead).
 *
 * The endpoint's response is normalized (`{episodes, rows}`, each row
 * referencing its episode by `episode_nid` rather than repeating its
 * title/mp3/game on every one of its ~500 segments); denormalize() below
 * expands it back into the flat per-row shape the rest of this file
 * expects.
 */

(function (Drupal) {
  'use strict';

  const DATA_URL = '/dynasty/search/transcripts';
  const RESULTS_PER_PAGE = 25;
  const DEBOUNCE_MS = 300;
  const SNIPPET_CONTEXT = 100;

  Drupal.behaviors.transcriptSearch = {
    attach: function (context, settings) {
      const app = context.querySelector('#transcript-search-app');
      if (!app || app.dataset.transcriptSearchInitialized) {
        return;
      }
      app.dataset.transcriptSearchInitialized = 'true';

      const input = app.querySelector('#ts-input');
      const searchBtn = app.querySelector('#ts-search-btn');
      const statusEl = app.querySelector('#ts-status');
      const resultsEl = app.querySelector('#ts-results');
      const paginationEl = app.querySelector('#ts-pagination');

      const speakerFilter = app.querySelector('#ts-filter-speaker');
      const seasonFilter = app.querySelector('#ts-filter-season');
      const typeFilter = app.querySelector('#ts-filter-type');
      const clearFiltersBtn = app.querySelector('#ts-clear-filters');
      const activeFiltersEl = app.querySelector('#ts-active-filters');

      if (!input || !searchBtn || !statusEl || !resultsEl || !paginationEl) {
        return;
      }

      let rows = [];
      let debounceTimer = null;
      let currentPage = 0;

      statusEl.textContent = 'Loading transcripts…';

      fetch(DATA_URL)
        .then(function (r) {
          if (!r.ok) throw new Error('HTTP ' + r.status);
          return r.json();
        })
        .then(function (data) {
          rows = denormalize(data);
          statusEl.textContent = '';
          populateFacets();
          bindEvents();
        })
        .catch(function (err) {
          statusEl.textContent = '';
          resultsEl.innerHTML = '<div class="ts-error text-red-600 p-4 bg-red-50 rounded-md mt-4">Failed to load transcripts: ' + escapeHtml(err.message) + '</div>';
        });

      // --- Data normalization ---

      function denormalize(data) {
        const episodes = data.episodes || {};
        return (data.rows || []).map(function (r) {
          const e = episodes[r.episode_nid] || {};
          return Object.assign({}, r, {
            episode_title: e.title,
            episode_url: e.url,
            season: e.season,
            episode_num: e.episode,
            mp3: e.mp3,
            game_url: e.game_url,
            game_title: e.game_title,
          });
        });
      }

      // --- Facets (with counts, matching the old Solr-facet UI) ---

      function populateFacets() {
        if (speakerFilter) {
          fillOptions(speakerFilter, countBy(rows, function (r) { return r.speaker; }), 'All Speakers');
        }
        if (seasonFilter) {
          const counts = countBy(rows, function (r) { return r.season; });
          const sorted = Array.from(counts.entries()).sort(function (a, b) {
            return parseInt(a[0], 10) - parseInt(b[0], 10);
          });
          fillOptions(seasonFilter, new Map(sorted), 'All Seasons');
        }
      }

      function countBy(list, getter) {
        const counts = new Map();
        list.forEach(function (r) {
          const v = getter(r);
          if (!v) return;
          counts.set(v, (counts.get(v) || 0) + 1);
        });
        return new Map(Array.from(counts.entries()).sort(function (a, b) { return a[0] < b[0] ? -1 : 1; }));
      }

      function fillOptions(select, counts, allLabel) {
        const currentValue = select.value;
        select.innerHTML = '<option value="">' + allLabel + '</option>';
        counts.forEach(function (count, value) {
          const option = document.createElement('option');
          option.value = value;
          option.textContent = value + ' (' + count + ')';
          select.appendChild(option);
        });
        select.value = currentValue;
      }

      // --- Events ---

      function bindEvents() {
        input.addEventListener('keyup', function (e) {
          if (e.key === 'Enter') {
            clearTimeout(debounceTimer);
            doSearch(0);
            return;
          }
          clearTimeout(debounceTimer);
          debounceTimer = setTimeout(function () { doSearch(0); }, DEBOUNCE_MS);
        });

        searchBtn.addEventListener('click', function () {
          clearTimeout(debounceTimer);
          doSearch(0);
        });

        [speakerFilter, seasonFilter, typeFilter].forEach(function (select) {
          if (select) select.addEventListener('change', function () { doSearch(0); });
        });

        if (clearFiltersBtn) {
          clearFiltersBtn.addEventListener('click', function () {
            clearFilters();
            doSearch(0);
          });
        }

        if (activeFiltersEl) {
          activeFiltersEl.addEventListener('click', function (e) {
            const btn = e.target.closest('.ts-remove-filter');
            if (!btn) return;
            const filter = btn.dataset.filter;
            if (filter === 'speaker' && speakerFilter) speakerFilter.value = '';
            if (filter === 'season' && seasonFilter) seasonFilter.value = '';
            if (filter === 'episode_type' && typeFilter) typeFilter.value = '';
            doSearch(0);
          });
        }
      }

      function getFilters() {
        return {
          q: input.value.trim(),
          speaker: speakerFilter ? speakerFilter.value : '',
          season: seasonFilter ? seasonFilter.value : '',
          episode_type: typeFilter ? typeFilter.value : '',
        };
      }

      function hasActiveFilters(f) {
        return Boolean(f.speaker || f.season || f.episode_type);
      }

      function clearFilters() {
        if (speakerFilter) speakerFilter.value = '';
        if (seasonFilter) seasonFilter.value = '';
        if (typeFilter) typeFilter.value = '';
        updateFilterUI(getFilters());
      }

      function updateFilterUI(f) {
        const active = hasActiveFilters(f);
        if (clearFiltersBtn) clearFiltersBtn.classList.toggle('hidden', !active);
        if (!activeFiltersEl) return;

        if (!active) {
          activeFiltersEl.innerHTML = '';
          activeFiltersEl.classList.add('hidden');
          return;
        }

        let html = '';
        if (f.speaker) html += chip('speaker', 'Speaker', f.speaker, 'blue');
        if (f.season) html += chip('season', 'Season', f.season, 'green');
        if (f.episode_type) html += chip('episode_type', 'Type', f.episode_type === 'game' ? 'Game Recaps' : 'Bonus Episodes', 'purple');
        activeFiltersEl.innerHTML = html;
        activeFiltersEl.classList.remove('hidden');
      }

      function chip(filter, label, value, color) {
        return '<span class="inline-flex items-center gap-1 px-2 py-1 bg-' + color + '-100 text-' + color + '-800 text-xs rounded-full">'
          + escapeHtml(label) + ': ' + escapeHtml(String(value))
          + '<button class="ts-remove-filter hover:text-red-600" data-filter="' + filter + '">&times;</button>'
          + '</span>';
      }

      // --- Search ---

      function matches(r, f, ql) {
        if (ql && r.text.toLowerCase().indexOf(ql) === -1) return false;
        if (f.speaker && r.speaker !== f.speaker) return false;
        if (f.season && String(r.season) !== f.season) return false;
        if (f.episode_type === 'game' && !r.game_url) return false;
        if (f.episode_type === 'bonus' && r.game_url) return false;
        return true;
      }

      function doSearch(page) {
        const f = getFilters();
        updateFilterUI(f);

        if (!f.q && !hasActiveFilters(f)) {
          statusEl.textContent = '';
          resultsEl.innerHTML = '';
          paginationEl.innerHTML = '';
          return;
        }

        currentPage = page;
        const ql = f.q.toLowerCase();
        const matched = rows.filter(function (r) { return matches(r, f, ql); });
        renderResults(matched, f.q, page);
      }

      // --- Render ---

      function renderResults(matched, query, page) {
        const total = matched.length;
        const totalPages = Math.max(1, Math.ceil(total / RESULTS_PER_PAGE));

        let statusText = total ? 'Found <strong>' + total.toLocaleString() + '</strong> results' : 'No results found';
        if (query) {
          statusText += ' for <span class="ts-query-term font-semibold text-red-pats">"' + escapeHtml(query) + '"</span>';
        }
        statusEl.innerHTML = statusText;

        if (!total) {
          resultsEl.innerHTML = '';
          paginationEl.innerHTML = '';
          return;
        }

        const start = page * RESULTS_PER_PAGE;
        const pageRows = matched.slice(start, start + RESULTS_PER_PAGE);
        resultsEl.innerHTML = pageRows.map(function (r) { return renderRow(r, query); }).join('');
        renderPagination(page, totalPages);
      }

      function renderRow(r, query) {
        const playUrl = r.mp3 ? r.mp3 + '#t=' + r.start : '#';
        const audioId = 'ts-' + Math.floor(Math.random() * 999999);
        const snippetHtml = buildSnippet(r.text, query);

        return '\
          <div class="border-b border-gray-400 flex gap-4 py-4">\
            <div class="w-1/3 flex">\
              <div class="bg-white border border-gray-300 shadow-md shadow-gray-500 flex gap-1 h-fit justify-center my-auto mx-auto px-2 rounded-full">\
                <audio id="pod-' + audioId + '" src="' + escapeHtml(playUrl) + '"></audio>\
                <div onclick="skipBackwards(\'pod-' + audioId + '\')" id="back-pod-' + audioId + '" class="my-auto text-white transition-all duration-300 ease-out opacity-0 -mr-6 scale-0 cursor-pointer">\
                  <img src="/themes/custom/dynasty_tw/icons/back15-blue.svg" class="w-6 h-6" alt="Back 15 seconds" />\
                </div>\
                <div onclick="playPause(\'pod-' + audioId + '\')" class="cursor-pointer bg-red-pats outline outline-red-pats outline-4 p-4 rounded-full shadow-gray-500 shadow-md text-white w-12">\
                  <img src="/themes/custom/dynasty_tw/icons/pod-play.svg" id="playpod-' + audioId + '" class="w-auto h-auto" alt="Play" />\
                </div>\
                <div id="fwd-pod-' + audioId + '" onclick="skipForward(\'pod-' + audioId + '\')" class="my-auto text-white transition-all duration-300 ease-out opacity-0 -ml-6 scale-0 cursor-pointer">\
                  <img src="/themes/custom/dynasty_tw/icons/plus15-blue.svg" class="w-6 h-6" alt="Forward 15 seconds" />\
                </div>\
              </div>\
            </div>\
            <div class="ts-result w-2/3">\
              ' + (r.episode_title ? '<div class="ts-result-episode text-xs text-gray-500 uppercase tracking-wide mb-1">' + escapeHtml(r.episode_title) + (r.season ? ' <span class="text-blue-600">(Season ' + escapeHtml(String(r.season)) + ')</span>' : '') + '</div>' : '') + '\
              <div class="ts-result-title">\
                <a class="cursor-pointer text-lg font-semibold text-blue-pats hover:underline" onclick="playPause(\'pod-' + audioId + '\')">\
                  ' + escapeHtml(r.timestamp) + (r.speaker ? ' - <strong>' + escapeHtml(r.speaker) + '</strong>' : '') + '\
                </a>\
              </div>\
              <div class="ts-result-snippet mt-2 text-sm text-gray-700 leading-relaxed">' + snippetHtml + '</div>\
            </div>\
          </div>';
      }

      // Wraps the (first occurrence of the) matched query in <mark>, with
      // ~100 characters of surrounding context on each side -- roughly
      // matching the old Solr highlighter's hl.fragsize=200. Falls back
      // to a plain leading truncation when there's no query to highlight
      // (a speaker/season/type-only filter).
      function buildSnippet(text, query) {
        if (!query) {
          return escapeHtml(text.substring(0, 200)) + (text.length > 200 ? '…' : '');
        }
        const idx = text.toLowerCase().indexOf(query.toLowerCase());
        if (idx === -1) {
          return escapeHtml(text.substring(0, 200)) + (text.length > 200 ? '…' : '');
        }
        const start = Math.max(0, idx - SNIPPET_CONTEXT);
        const end = Math.min(text.length, idx + query.length + SNIPPET_CONTEXT);
        const before = escapeHtml(text.substring(start, idx));
        const match = escapeHtml(text.substring(idx, idx + query.length));
        const after = escapeHtml(text.substring(idx + query.length, end));
        return (start > 0 ? '…' : '') + before + '<mark>' + match + '</mark>' + after + (end < text.length ? '…' : '');
      }

      function renderPagination(page, totalPages) {
        if (totalPages <= 1) {
          paginationEl.innerHTML = '';
          return;
        }
        paginationEl.innerHTML =
          '<button class="px-4 py-2 border border-gray-300 bg-white rounded cursor-pointer disabled:opacity-40 disabled:cursor-default" ' + (page <= 0 ? 'disabled' : '') + ' data-page="' + (page - 1) + '">&larr; Prev</button>'
          + '<span class="px-2 py-2 text-gray-600">Page ' + (page + 1) + ' of ' + totalPages + '</span>'
          + '<button class="px-4 py-2 border border-gray-300 bg-white rounded cursor-pointer disabled:opacity-40 disabled:cursor-default" ' + (page >= totalPages - 1 ? 'disabled' : '') + ' data-page="' + (page + 1) + '">Next &rarr;</button>';

        paginationEl.querySelectorAll('button[data-page]').forEach(function (btn) {
          btn.addEventListener('click', function () {
            if (btn.disabled) return;
            doSearch(parseInt(btn.dataset.page, 10));
            app.scrollIntoView({ behavior: 'smooth' });
          });
        });
      }

      function escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
      }
    },
  };

})(Drupal);
