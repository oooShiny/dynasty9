/**
 * @file
 * Transcript search functionality with faceted filtering.
 */

(function (Drupal) {
  'use strict';

  // ============================================================
  // CONFIG
  // ============================================================
  const SEARCH_URL = "/api/transcript-search";
  const TRANSCRIPT_FIELD = "tm_X3b_und_transcript";
  const RESULTS_PER_PAGE = 25;
  const DEBOUNCE_MS = 300;
  // ============================================================

  Drupal.behaviors.transcriptSearch = {
    attach: function (context, settings) {
      const app = context.querySelector('#transcript-search-app');
      if (!app || app.dataset.transcriptSearchInitialized) {
        return;
      }
      app.dataset.transcriptSearchInitialized = 'true';

      const input = app.querySelector("#ts-input");
      const searchBtn = app.querySelector("#ts-search-btn");
      const statusEl = app.querySelector("#ts-status");
      const resultsEl = app.querySelector("#ts-results");
      const paginationEl = app.querySelector("#ts-pagination");

      // Filter elements
      const speakerFilter = app.querySelector("#ts-filter-speaker");
      const seasonFilter = app.querySelector("#ts-filter-season");
      const typeFilter = app.querySelector("#ts-filter-type");
      const clearFiltersBtn = app.querySelector("#ts-clear-filters");
      const activeFiltersEl = app.querySelector("#ts-active-filters");

      if (!input || !searchBtn || !statusEl || !resultsEl || !paginationEl) {
        return;
      }

      let debounceTimer = null;
      let currentQuery = "";
      let currentPage = 0;
      let currentFacets = {};
      let facetsLoaded = false;

      // Note: We don't pre-load facets on page load to avoid Cloudflare blocking.
      // Facets will populate with the first search request.

      // --- Event listeners ---
      input.addEventListener("keyup", function (e) {
        if (e.key === "Enter") {
          clearTimeout(debounceTimer);
          doSearch(0);
          return;
        }
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function () {
          doSearch(0);
        }, DEBOUNCE_MS);
      });

      searchBtn.addEventListener("click", function () {
        clearTimeout(debounceTimer);
        doSearch(0);
      });

      // Filter change handlers
      if (speakerFilter) {
        speakerFilter.addEventListener("change", function () {
          doSearch(0);
        });
      }
      if (seasonFilter) {
        seasonFilter.addEventListener("change", function () {
          doSearch(0);
        });
      }
      if (typeFilter) {
        typeFilter.addEventListener("change", function () {
          doSearch(0);
        });
      }
      if (clearFiltersBtn) {
        clearFiltersBtn.addEventListener("click", function () {
          clearFilters();
          doSearch(0);
        });
      }

      // --- Load initial facet values ---
      async function loadFacets() {
        try {
          const response = await fetch(SEARCH_URL + "?facets=1");
          if (response.ok) {
            const data = await response.json();
            if (data.facets) {
              updateFacetOptions(data.facets);
            }
          }
        } catch (err) {
          console.error("Failed to load facets:", err);
        }
      }

      // --- Update facet dropdown options ---
      function updateFacetOptions(facets) {
        currentFacets = facets;

        // Update speaker dropdown
        if (speakerFilter && facets.ss_speaker) {
          const currentValue = speakerFilter.value;
          speakerFilter.innerHTML = '<option value="">All Speakers</option>';
          facets.ss_speaker.forEach(function (item) {
            const option = document.createElement("option");
            option.value = item.value;
            option.textContent = item.value + " (" + item.count + ")";
            speakerFilter.appendChild(option);
          });
          speakerFilter.value = currentValue;
        }

        // Update season dropdown
        if (seasonFilter && facets.ss_season) {
          const currentValue = seasonFilter.value;
          seasonFilter.innerHTML = '<option value="">All Seasons</option>';
          // Sort seasons numerically
          const sortedSeasons = [...facets.ss_season].sort(function (a, b) {
            return parseInt(a.value) - parseInt(b.value);
          });
          sortedSeasons.forEach(function (item) {
            const option = document.createElement("option");
            option.value = item.value;
            option.textContent = item.value + " (" + item.count + ")";
            seasonFilter.appendChild(option);
          });
          seasonFilter.value = currentValue;
        }
      }

      // --- Get current filter values ---
      function getFilters() {
        return {
          speaker: speakerFilter ? speakerFilter.value : "",
          season: seasonFilter ? seasonFilter.value : "",
          episode_type: typeFilter ? typeFilter.value : ""
        };
      }

      // --- Check if any filters are active ---
      function hasActiveFilters() {
        const filters = getFilters();
        return filters.speaker || filters.season || filters.episode_type;
      }

      // --- Clear all filters ---
      function clearFilters() {
        if (speakerFilter) speakerFilter.value = "";
        if (seasonFilter) seasonFilter.value = "";
        if (typeFilter) typeFilter.value = "";
        updateFilterUI();
      }

      // --- Update filter UI (clear button, active filters display) ---
      function updateFilterUI() {
        const hasFilters = hasActiveFilters();

        // Show/hide clear button
        if (clearFiltersBtn) {
          clearFiltersBtn.classList.toggle("hidden", !hasFilters);
        }

        // Update active filters display
        if (activeFiltersEl) {
          if (hasFilters) {
            const filters = getFilters();
            let html = "";

            if (filters.speaker) {
              html += '<span class="inline-flex items-center gap-1 px-2 py-1 bg-blue-100 text-blue-800 text-xs rounded-full">' +
                'Speaker: ' + escapeHtml(filters.speaker) +
                '<button class="ts-remove-filter hover:text-red-600" data-filter="speaker">&times;</button>' +
                '</span>';
            }
            if (filters.season) {
              html += '<span class="inline-flex items-center gap-1 px-2 py-1 bg-green-100 text-green-800 text-xs rounded-full">' +
                'Season: ' + escapeHtml(filters.season) +
                '<button class="ts-remove-filter hover:text-red-600" data-filter="season">&times;</button>' +
                '</span>';
            }
            if (filters.episode_type) {
              const typeLabel = filters.episode_type === 'game' ? 'Game Recaps' : 'Bonus Episodes';
              html += '<span class="inline-flex items-center gap-1 px-2 py-1 bg-purple-100 text-purple-800 text-xs rounded-full">' +
                escapeHtml(typeLabel) +
                '<button class="ts-remove-filter hover:text-red-600" data-filter="episode_type">&times;</button>' +
                '</span>';
            }

            activeFiltersEl.innerHTML = html;
            activeFiltersEl.classList.remove("hidden");

            // Add click handlers for remove buttons
            activeFiltersEl.querySelectorAll(".ts-remove-filter").forEach(function (btn) {
              btn.addEventListener("click", function () {
                const filter = btn.dataset.filter;
                if (filter === "speaker" && speakerFilter) speakerFilter.value = "";
                if (filter === "season" && seasonFilter) seasonFilter.value = "";
                if (filter === "episode_type" && typeFilter) typeFilter.value = "";
                doSearch(0);
              });
            });
          } else {
            activeFiltersEl.innerHTML = "";
            activeFiltersEl.classList.add("hidden");
          }
        }
      }

      // --- Search ---
      async function doSearch(page) {
        const query = input.value.trim();
        const filters = getFilters();

        if (!query && !hasActiveFilters()) {
          statusEl.textContent = "";
          resultsEl.innerHTML = "";
          paginationEl.innerHTML = "";
          updateFilterUI();
          return;
        }

        currentQuery = query;
        currentPage = page;

        resultsEl.innerHTML = '<div class="ts-loading text-center py-8 text-gray-500">Searching...</div>';
        paginationEl.innerHTML = "";
        statusEl.textContent = "";
        updateFilterUI();

        try {
          const params = new URLSearchParams({
            q: query,
            start: (page * RESULTS_PER_PAGE).toString(),
            rows: RESULTS_PER_PAGE.toString(),
          });

          // Add filter parameters
          if (filters.speaker) params.append("speaker", filters.speaker);
          if (filters.season) params.append("season", filters.season);
          if (filters.episode_type) params.append("episode_type", filters.episode_type);

          const response = await fetch(SEARCH_URL + "?" + params);

          if (!response.ok) {
            const err = await response.json().catch(function () {
              return {};
            });
            throw new Error(err.error?.msg || "HTTP " + response.status);
          }

          const data = await response.json();

          // Update facet counts based on current results
          if (data.facets) {
            updateFacetOptions(data.facets);
          }

          renderResults(data, query, page);
        } catch (err) {
          resultsEl.innerHTML = '<div class="ts-error text-red-600 p-4 bg-red-50 rounded-md mt-4">Search failed: ' + escapeHtml(err.message) + '</div>';
        }
      }

      // --- Render results ---
      function renderResults(data, query, page) {
        const total = data.response?.numFound || 0;
        const docs = data.response?.docs || [];
        const highlighting = data.highlighting || {};
        const totalPages = Math.ceil(total / RESULTS_PER_PAGE);

        // Status line
        if (total) {
          let statusText = 'Found <strong>' + total.toLocaleString() + '</strong> results';
          if (query) {
            statusText += ' for <span class="ts-query-term font-semibold text-red-pats">"' + escapeHtml(query) + '"</span>';
          }
          statusEl.innerHTML = statusText;
        } else {
          let statusText = 'No results found';
          if (query) {
            statusText += ' for <span class="ts-query-term font-semibold text-red-pats">"' + escapeHtml(query) + '"</span>';
          }
          statusEl.innerHTML = statusText;
        }

        if (!docs.length) {
          resultsEl.innerHTML = "";
          paginationEl.innerHTML = "";
          return;
        }

        // Results
        resultsEl.innerHTML = docs.map(function (doc) {
          const docId = doc.id;
          const episodeTitle = doc.ss_episode_title || "";
          const transcript = doc[TRANSCRIPT_FIELD]?.[0] || "";
          const mp3Url = doc.ss_mp3 || "";
          const speaker = doc.ss_speaker || "";
          const timestampDisplay = doc.ss_timestamp_display || "";
          const timestampSec = doc.its_timestamp_start || 0;
          const season = doc.ss_season || "";

          // Get highlighted snippet or fall back to transcript
          const hlSnippets = highlighting[docId]?.[TRANSCRIPT_FIELD] || [];
          const snippetHtml = hlSnippets.length > 0
            ? hlSnippets.join(" ... ")
            : escapeHtml(transcript.substring(0, 200)) + (transcript.length > 200 ? "..." : "");

          // Build MP3 URL with timestamp for direct playback
          const playUrl = mp3Url ? mp3Url + "#t=" + timestampSec : "#";
          const audioId = "ts-" + Math.floor(Math.random() * 999999);

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
                ' + (episodeTitle ? '<div class="ts-result-episode text-xs text-gray-500 uppercase tracking-wide mb-1">' + escapeHtml(episodeTitle) + (season ? ' <span class="text-blue-600">(Season ' + escapeHtml(season) + ')</span>' : '') + '</div>' : '') + '\
                <div class="ts-result-title">\
                  <a class="cursor-pointer text-lg font-semibold text-blue-pats hover:underline" onclick="playPause(\'pod-' + audioId + '\')">\
                    ' + escapeHtml(timestampDisplay) + (speaker ? ' - <strong>' + escapeHtml(speaker) + '</strong>' : '') + '\
                  </a>\
                </div>\
                <div class="ts-result-snippet mt-2 text-sm text-gray-700 leading-relaxed">' + snippetHtml + '</div>\
              </div>\
            </div>';
        }).join("");

        // Pagination
        if (totalPages > 1) {
          var html = "";
          html += '<button class="px-4 py-2 border border-gray-300 bg-white rounded cursor-pointer disabled:opacity-40 disabled:cursor-default" ' + (page <= 0 ? "disabled" : "") + ' data-page="' + (page - 1) + '">&larr; Prev</button>';
          html += '<span class="px-2 py-2 text-gray-600">Page ' + (page + 1) + ' of ' + totalPages + '</span>';
          html += '<button class="px-4 py-2 border border-gray-300 bg-white rounded cursor-pointer disabled:opacity-40 disabled:cursor-default" ' + (page >= totalPages - 1 ? "disabled" : "") + ' data-page="' + (page + 1) + '">Next &rarr;</button>';
          paginationEl.innerHTML = html;

          // Add pagination click handlers
          paginationEl.querySelectorAll('button[data-page]').forEach(function (btn) {
            btn.addEventListener('click', function () {
              if (!btn.disabled) {
                doSearch(parseInt(btn.dataset.page, 10));
                app.scrollIntoView({ behavior: "smooth" });
              }
            });
          });
        } else {
          paginationEl.innerHTML = "";
        }
      }

      function escapeHtml(str) {
        if (!str) return "";
        const div = document.createElement("div");
        div.textContent = str;
        return div.innerHTML;
      }
    }
  };

})(Drupal);
