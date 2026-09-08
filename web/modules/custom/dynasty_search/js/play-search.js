/**
 * @file
 * Play Search: fetches /dynasty/search/plays once, then does all
 * filtering/sorting/pagination client-side.
 */

(function (Drupal, once) {
  'use strict';

  const DATA_URL = '/dynasty/search/plays';
  const DEBOUNCE_MS = 300;
  const PER_PAGE = 12;

  const DOWNLOAD_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true">' +
    '<path class="fill-white" d="M11.24 13.59L11.24 4C11.24 3.45 11.69 3 12.24 3C12.8 3 13.24 3.45 13.24 4L13.24 13.59' +
    'L15.78 11.05C16.17 10.66 16.8 10.66 17.19 11.05C17.58 11.44 17.58 12.07 17.19 12.46' +
    'L12.95 16.71C12.56 17.1 11.93 17.1 11.54 16.71L7.29 12.46C6.9 12.07 6.9 11.44 7.29 11.05' +
    'C7.68 10.66 8.32 10.66 8.71 11.05L11.24 13.59Z' +
    'M2 14C2 13.45 2.45 13 3 13C3.55 13 4 13.45 4 14C4 14.98 4 17.39 4 18' +
    'C4 18.55 4.45 19 5 19L19 19C19.55 19 20 18.55 20 18L20 14' +
    'C20 13.45 20.45 13 21 13C21.55 13 22 13.45 22 14L22 18' +
    'C22 19.66 20.66 21 19 21L5 21C3.34 21 2 19.66 2 18C2 17.39 2 14.98 2 14Z" />' +
    '</svg>';

  Drupal.behaviors.playSearch = {
    attach: function (context) {
      once('play-search-init', '#play-search-app', context).forEach(function (app) {
        initPlaySearch(app);
      });
    }
  };

  function initPlaySearch(app) {
    const filterToggle = app.querySelector('#ps-filter-toggle');
    const filterPanel = app.querySelector('#ps-filters-panel');
    if (filterToggle && filterPanel) {
      filterToggle.addEventListener('click', function () {
        filterPanel.classList.toggle('hidden');
        filterPanel.classList.toggle('block');
      });
    }

    const els = {
      search: app.querySelector('#ps-search'),
      sort: app.querySelector('#ps-sort'),
      results: app.querySelector('#ps-results'),
      pagination: app.querySelector('#ps-pagination'),
      summary: app.querySelector('#ps-result-summary'),
      activeFilters: app.querySelector('#ps-active-filters'),
      clearFilters: app.querySelector('#ps-clear-filters'),
      playType: app.querySelector('#ps-filter-play-type'),
      player: app.querySelector('#ps-filter-player'),
      playTag: app.querySelector('#ps-filter-play-tag'),
      season: app.querySelector('#ps-filter-season'),
      down: app.querySelector('#ps-filter-down'),
      quarter: app.querySelector('#ps-filter-quarter'),
      reset: app.querySelector('#ps-reset'),
    };

    let plays = [];
    let currentPage = 0;
    let debounceTimer = null;
    let restoring = false;
    const sliders = {};

    fetch(DATA_URL)
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(function (data) {
        plays = data;
        updateFilterOptions(getFilters());
        initRangeSliders();
        bindEvents();
        initToggleDefaults();
        restoreFromUrl();
        render();
      })
      .catch(function (err) {
        if (els.results) {
          els.results.innerHTML = '<div class="p-5">Failed to load plays: ' + escapeHtml(err.message) + '</div>';
        }
      });

    // --- Filter option lists ---

    // Cross-filtering: each select's option list is recomputed from plays
    // matching every OTHER active filter (excluding its own dimension, so
    // the current selection stays visible/editable) -- e.g. picking a
    // season narrows Play Type to only types that actually occurred that
    // season.
    function updateFilterOptions(f) {
      const withoutPlayType = plays.filter(function (p) { return matches(p, f, 'playType'); });
      const withoutPlayer = plays.filter(function (p) { return matches(p, f, 'player'); });
      const withoutPlayTag = plays.filter(function (p) { return matches(p, f, 'playTag'); });
      const withoutSeason = plays.filter(function (p) { return matches(p, f, 'season'); });
      const withoutDown = plays.filter(function (p) { return matches(p, f, 'down'); });
      const withoutQuarter = plays.filter(function (p) { return matches(p, f, 'quarter'); });

      const wasRestoring = restoring;
      restoring = true;
      fillSelect(els.playType, uniqueSorted(withoutPlayType, function (p) { return p.play_type ? p.play_type.label : null; }));
      fillSelect(els.player, uniqueSorted(withoutPlayer, null, function (p) { return p.players_involved || []; }));
      fillSelect(els.playTag, uniqueSorted(withoutPlayTag, null, function (p) { return p.tag_play || []; }));
      fillSelect(els.season, uniqueSorted(withoutSeason, function (p) { return String(p.season); }).sort(function (a, b) { return Number(b) - Number(a); }));
      fillSelect(els.down, uniqueSorted(withoutDown, function (p) { return p.down ? String(p.down) : null; }).sort(function (a, b) { return Number(a) - Number(b); }));
      fillSelect(els.quarter, uniqueSorted(withoutQuarter, function (p) { return p.quarter ? String(p.quarter) : null; }).sort(function (a, b) { return Number(a) - Number(b); }));
      [els.playType, els.player, els.playTag, els.season, els.down, els.quarter].forEach(refreshSelect2);
      restoring = wasRestoring;
    }

    function fillSelect(select, values) {
      if (!select) return;
      const currentlySelected = Array.from(select.selectedOptions).map(function (o) { return o.value; });
      select.innerHTML = values.map(function (v) {
        const sel = currentlySelected.indexOf(v) !== -1 ? ' selected' : '';
        return '<option value="' + escapeHtml(v) + '"' + sel + '>' + escapeHtml(v) + '</option>';
      }).join('');
    }

    function uniqueSorted(rows, getter, multiGetter) {
      const set = new Set();
      rows.forEach(function (r) {
        if (multiGetter) {
          multiGetter(r).forEach(function (v) { if (v) set.add(v); });
        } else {
          const v = getter(r);
          if (v) set.add(v);
        }
      });
      return Array.from(set).sort();
    }

    function refreshSelect2(select) {
      if (select && window.jQuery && window.jQuery(select).data('select2')) {
        window.jQuery(select).trigger('change');
      }
    }

    // --- Range sliders ---

    function initRangeSliders() {
      sliders.yards = createRangeSlider('ps-slider-yards', fieldRange(plays, 'yards_gained'));
      sliders.airYards = createRangeSlider('ps-slider-air-yards', fieldRange(plays, 'air_yards'));
    }

    function fieldRange(rows, field) {
      let min = Infinity, max = -Infinity;
      rows.forEach(function (r) {
        const v = r[field];
        if (v === null || v === undefined) return;
        if (v < min) min = v;
        if (v > max) max = v;
      });
      if (min === Infinity) { min = 0; max = 0; }
      return { min: min, max: max };
    }

    function createRangeSlider(elId, range) {
      const el = app.querySelector('#' + elId);
      if (!el || typeof noUiSlider === 'undefined') return null;
      noUiSlider.create(el, {
        start: [range.min, range.max],
        connect: true,
        range: { min: range.min, max: range.max > range.min ? range.max : range.min + 1 },
        step: 1,
        tooltips: [true, true],
        format: {
          to: function (v) { return Math.round(v); },
          from: function (v) { return Number(v); }
        }
      });
      const labels = el.parentElement.querySelector('.slider-min-max-labels');
      if (labels) {
        const minLabel = labels.querySelector('.min-label');
        const maxLabel = labels.querySelector('.max-label');
        if (minLabel) minLabel.textContent = range.min;
        if (maxLabel) maxLabel.textContent = range.max;
      }
      el.noUiSlider.on('update', function () {
        if (restoring) return;
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(onFilterChange, DEBOUNCE_MS);
      });
      return { el: el, range: range };
    }

    function sliderRange(slider) {
      if (!slider || !slider.el.noUiSlider) return [null, null];
      const values = slider.el.noUiSlider.get().map(Number);
      const min = values[0] <= slider.range.min ? null : values[0];
      const max = values[1] >= slider.range.max ? null : values[1];
      return [min, max];
    }

    function setSliderFromUrl(slider, minParam, maxParam) {
      if (!slider || !slider.el.noUiSlider) return;
      const min = (minParam !== null && minParam !== '') ? Number(minParam) : slider.range.min;
      const max = (maxParam !== null && maxParam !== '') ? Number(maxParam) : slider.range.max;
      slider.el.noUiSlider.set([min, max]);
    }

    // --- Events ---

    function bindEvents() {
      if (els.search) {
        els.search.addEventListener('input', function () {
          clearTimeout(debounceTimer);
          debounceTimer = setTimeout(onFilterChange, DEBOUNCE_MS);
        });
      }
      if (els.sort) {
        els.sort.addEventListener('change', onFilterChange);
      }

      [els.playType, els.player, els.playTag, els.season, els.down, els.quarter].forEach(function (select) {
        if (!select) return;
        select.addEventListener('change', onFilterChange);
        if (window.jQuery) window.jQuery(select).on('change', onFilterChange);
      });

      app.querySelectorAll('.ps-toggle-group').forEach(function (group) {
        group.querySelectorAll('.ps-toggle').forEach(function (btn) {
          btn.addEventListener('click', function () {
            group.querySelectorAll('.ps-toggle').forEach(function (b) { b.classList.remove('ps-toggle-active'); });
            btn.classList.add('ps-toggle-active');
            onFilterChange();
          });
        });
      });


      app.querySelectorAll('.ps-popular-search').forEach(function (btn) {
        btn.addEventListener('click', function () {
          if (els.search) els.search.value = btn.dataset.search;
          onFilterChange();
        });
      });

      if (els.reset) {
        els.reset.addEventListener('click', function () {
          resetFilters();
          onFilterChange();
        });
      }

      if (els.clearFilters) {
        els.clearFilters.addEventListener('click', function () {
          resetFilters();
          onFilterChange();
        });
      }

      if (els.activeFilters) {
        els.activeFilters.addEventListener('click', function (e) {
          const btn = e.target.closest('[data-remove]');
          if (!btn) return;
          removeFilter(btn.dataset.remove);
          onFilterChange();
        });
      }

      if (els.pagination) {
        els.pagination.addEventListener('click', function (e) {
          const btn = e.target.closest('[data-page]');
          if (!btn || btn.disabled) return;
          currentPage = parseInt(btn.dataset.page, 10);
          renderResults();
          app.scrollIntoView({ behavior: 'smooth' });
        });
      }
    }

    function initToggleDefaults() {
      app.querySelectorAll('.ps-toggle-group').forEach(function (group) {
        group.querySelector('.ps-toggle[data-value=""]').classList.add('ps-toggle-active');
      });
    }

    function onFilterChange() {
      if (restoring) return;
      currentPage = 0;
      syncUrl();
      render();
    }

    function resetFilters() {
      if (els.search) els.search.value = '';
      if (els.sort) els.sort.value = 'newest';
      [els.playType, els.player, els.playTag, els.season, els.down, els.quarter].forEach(function (select) {
        if (!select) return;
        Array.from(select.options).forEach(function (o) { o.selected = false; });
        refreshSelect2(select);
      });
      app.querySelectorAll('.ps-toggle-group').forEach(function (group) {
        group.querySelectorAll('.ps-toggle').forEach(function (b) { b.classList.remove('ps-toggle-active'); });
        group.querySelector('.ps-toggle[data-value=""]').classList.add('ps-toggle-active');
      });
      [sliders.yards, sliders.airYards].forEach(function (s) {
        if (s && s.el.noUiSlider) s.el.noUiSlider.set([s.range.min, s.range.max]);
      });
    }

    // --- Reading current filter state ---

    function selected(select) {
      return select ? Array.from(select.selectedOptions).map(function (o) { return o.value; }) : [];
    }

    function toggleValue(filterName) {
      const group = app.querySelector('.ps-toggle-group[data-filter="' + filterName + '"]');
      if (!group) return '';
      const active = group.querySelector('.ps-toggle-active');
      return active ? active.dataset.value : '';
    }

    function getFilters() {
      const yards = sliderRange(sliders.yards);
      const airYards = sliderRange(sliders.airYards);
      return {
        q: els.search ? els.search.value.trim().toLowerCase() : '',
        sort: els.sort ? els.sort.value : 'newest',
        playType: selected(els.playType),
        player: selected(els.player),
        playTag: selected(els.playTag),
        season: selected(els.season),
        down: selected(els.down),
        quarter: selected(els.quarter),
        td_scored: toggleValue('td_scored'),
        minYards: yards[0],
        maxYards: yards[1],
        minAirYards: airYards[0],
        maxAirYards: airYards[1],
      };
    }

    function matches(p, f, excludeField) {
      if (f.q) {
        const haystack = [p.title, p.opponent, p.game_title].concat(p.players_involved || []).join(' ').toLowerCase();
        if (haystack.indexOf(f.q) === -1) return false;
      }
      if (excludeField !== 'playType' && f.playType.length && (!p.play_type || f.playType.indexOf(p.play_type.label) === -1)) return false;
      if (excludeField !== 'player' && f.player.length && !f.player.some(function (pl) { return (p.players_involved || []).indexOf(pl) !== -1; })) return false;
      if (excludeField !== 'playTag' && f.playTag.length && !f.playTag.some(function (t) { return (p.tag_play || []).indexOf(t) !== -1; })) return false;
      if (excludeField !== 'season' && f.season.length && f.season.indexOf(String(p.season)) === -1) return false;
      if (excludeField !== 'down' && f.down.length && f.down.indexOf(String(p.down)) === -1) return false;
      if (excludeField !== 'quarter' && f.quarter.length && f.quarter.indexOf(String(p.quarter)) === -1) return false;
      if (f.td_scored !== '' && Boolean(p.td_scored) !== (f.td_scored === '1')) return false;
      if (f.minYards !== null && p.yards_gained < f.minYards) return false;
      if (f.maxYards !== null && p.yards_gained > f.maxYards) return false;
      if (f.minAirYards !== null && p.air_yards < f.minAirYards) return false;
      if (f.maxAirYards !== null && p.air_yards > f.maxAirYards) return false;
      return true;
    }

    function sortPlays(rows, sort) {
      const sorted = rows.slice();
      if (sort === 'oldest') {
        sorted.sort(function (a, b) { return a.season - b.season; });
      } else if (sort === 'longest') {
        sorted.sort(function (a, b) { return (b.yards_gained || 0) - (a.yards_gained || 0); });
      } else {
        sorted.sort(function (a, b) { return b.season - a.season; });
      }
      return sorted;
    }

    // --- Render ---

    let filteredCache = [];

    function render() {
      const f = getFilters();
      filteredCache = sortPlays(plays.filter(function (p) { return matches(p, f); }), f.sort);
      renderActiveFilters(f);
      renderResults();
      updateFilterOptions(f);
    }

    function hasActiveFilters(f) {
      return Boolean(f.q) || f.playType.length > 0 || f.player.length > 0 || f.playTag.length > 0 || f.season.length > 0 ||
        f.down.length > 0 || f.quarter.length > 0 || f.td_scored !== '' ||
        f.minYards !== null || f.maxYards !== null || f.minAirYards !== null || f.maxAirYards !== null;
    }

    function renderActiveFilters(f) {
      if (els.clearFilters) {
        els.clearFilters.classList.toggle('hidden', !hasActiveFilters(f));
      }
      if (!els.activeFilters) return;
      const chips = [];
      if (f.q) chips.push(chip('q', 'Search: ' + f.q));
      f.playType.forEach(function (v) { chips.push(chip('playType:' + v, 'Play Type', v)); });
      f.player.forEach(function (v) { chips.push(chip('player:' + v, 'Player', v)); });
      f.playTag.forEach(function (v) { chips.push(chip('playTag:' + v, 'Tag', v)); });
      f.season.forEach(function (v) { chips.push(chip('season:' + v, 'Season', v)); });
      f.down.forEach(function (v) { chips.push(chip('down:' + v, 'Down', v)); });
      f.quarter.forEach(function (v) { chips.push(chip('quarter:' + v, 'Quarter', v)); });
      if (f.td_scored !== '') chips.push(chip('td_scored', 'TD', (f.td_scored === '1' ? 'Yes' : 'No')));
      els.activeFilters.innerHTML = chips.join('');
    }

    function chip(removeKey, label, filter) {
      return '<span class="bg-white border-2 border-red-pats gap-1 p-1 rounded-full">'
        + '<strong class="">' + escapeHtml(label) + ':</strong> ' + filter
        + '<button type="button" data-remove="' + escapeHtml(removeKey) + '" class="p-1 text-red-pats cursor-pointer">&times;</button>'
        + '</span>';
    }

    function removeFilter(key) {
      if (key === 'q') {
        if (els.search) els.search.value = '';
        return;
      }
      const [type, value] = key.split(/:(.*)/s);
      const map = { playType: els.playType, player: els.player, playTag: els.playTag, season: els.season, down: els.down, quarter: els.quarter };
      if (map[type]) {
        Array.from(map[type].options).forEach(function (o) {
          if (o.value === value) o.selected = false;
        });
        refreshSelect2(map[type]);
      } else {
        const group = app.querySelector('.ps-toggle-group[data-filter="' + type + '"]');
        if (group) {
          group.querySelectorAll('.ps-toggle').forEach(function (b) { b.classList.remove('ps-toggle-active'); });
          group.querySelector('.ps-toggle[data-value=""]').classList.add('ps-toggle-active');
        }
      }
    }

    function renderResults() {
      const total = filteredCache.length;
      const totalPages = Math.max(1, Math.ceil(total / PER_PAGE));
      if (currentPage >= totalPages) currentPage = totalPages - 1;
      if (currentPage < 0) currentPage = 0;

      const start = currentPage * PER_PAGE;
      const pageRows = filteredCache.slice(start, start + PER_PAGE);

      if (els.summary) {
        els.summary.textContent = total
          ? 'Displaying ' + (start + 1) + ' - ' + Math.min(start + PER_PAGE, total) + ' of ' + total + ' plays'
          : 'No plays match these filters';
      }

      if (els.results) {
        els.results.innerHTML = pageRows.map(renderCard).join('');
      }

      renderPagination(totalPages);
    }

    function renderPagination(totalPages) {
      if (!els.pagination) return;
      if (totalPages <= 1) {
        els.pagination.innerHTML = '';
        return;
      }
      let html = '';
      html += '<button class="px-4 py-2 border border-gray-300 bg-white rounded disabled:opacity-40" data-page="' +
        (currentPage - 1) + '" ' + (currentPage <= 0 ? 'disabled' : '') + '>&laquo;&laquo;</button>';
      html += '<span class="px-2 py-2">Page ' + (currentPage + 1) + ' of ' + totalPages + '</span>';
      html += '<button class="px-4 py-2 border border-gray-300 bg-white rounded disabled:opacity-40" data-page="' +
        (currentPage + 1) + '" ' + (currentPage >= totalPages - 1 ? 'disabled' : '') + '>&raquo;&raquo;</button>';
      els.pagination.innerHTML = html;
    }

    function renderCard(p) {
      const bg = p.td_scored ? 'bg-red-pats' : 'bg-blue-pats';
      const videoHtml = p.muse_id
        ? '<iframe src="https://skiv.com/embed/' + encodeURIComponent(p.muse_id) +
          '?links=0&search=0&title=0&controls=[-settings,-chromecast,-airplay]&&logo=0" class="bg-black w-full h-40 border-0" ' +
          'allowfullscreen allow="autoplay; fullscreen" loading="lazy"></iframe>'
        : '';
      const downloadHtml = p.video_file
        ? '<a href="https://cdn.skiv.com/w/' + encodeURIComponent(p.video_file) + '/videos/video.mp4" download class="" title="Download video">' +
          DOWNLOAD_SVG + '<span class="sr-only">Download video</span></a>'
        : '';
      return '<div class="w-1/2 md:w-1/3 lg:w-1/4 ' + bg + ' m-3 shadow-lg max-h-80 flex flex-col justify-between">' +
        videoHtml +
        '<div class="p-2 ' + bg + ' text-white flex flex-col gap-4 justify-between">' +
        '<div><a href="' + escapeHtml(p.url) + '">' + escapeHtml(p.title) + '</a></div>' +
        '<div class="flex justify-between">' +
        '<p class="badge badge-outline">' + escapeHtml(p.game_title || '') + '</p>' +
        downloadHtml +
        '</div></div></div>';
    }

    // --- URL state (shareable links, popular-search links) ---

    function syncUrl() {
      const f = getFilters();
      const params = new URLSearchParams();
      if (f.q) params.set('search', f.q);
      if (f.sort !== 'newest') params.set('sort', f.sort);
      f.playType.forEach(function (v) { params.append('play_type', v); });
      f.player.forEach(function (v) { params.append('player', v); });
      f.playTag.forEach(function (v) { params.append('play_tag', v); });
      f.season.forEach(function (v) { params.append('season', v); });
      f.down.forEach(function (v) { params.append('down', v); });
      f.quarter.forEach(function (v) { params.append('quarter', v); });
      if (f.td_scored !== '') params.set('td_scored', f.td_scored);
      if (f.minYards !== null) params.set('min_yards', f.minYards);
      if (f.maxYards !== null) params.set('max_yards', f.maxYards);
      if (f.minAirYards !== null) params.set('min_air_yards', f.minAirYards);
      if (f.maxAirYards !== null) params.set('max_air_yards', f.maxAirYards);
      const qs = params.toString();
      history.replaceState(null, '', qs ? '?' + qs : location.pathname);
    }

    function restoreFromUrl() {
      restoring = true;
      const params = new URLSearchParams(location.search);
      if (els.search && params.get('search')) els.search.value = params.get('search');
      if (els.sort && params.get('sort')) els.sort.value = params.get('sort');
      setMulti(els.playType, params.getAll('play_type'));
      setMulti(els.player, params.getAll('player'));
      setMulti(els.playTag, params.getAll('play_tag'));
      setMulti(els.season, params.getAll('season'));
      setMulti(els.down, params.getAll('down'));
      setMulti(els.quarter, params.getAll('quarter'));
      setToggle('td_scored', params.get('td_scored') || '');
      setSliderFromUrl(sliders.yards, params.get('min_yards'), params.get('max_yards'));
      setSliderFromUrl(sliders.airYards, params.get('min_air_yards'), params.get('max_air_yards'));
      restoring = false;
    }

    function setMulti(select, values) {
      if (!select || !values.length) return;
      Array.from(select.options).forEach(function (o) {
        o.selected = values.indexOf(o.value) !== -1;
      });
      refreshSelect2(select);
    }

    function setToggle(filterName, value) {
      const group = app.querySelector('.ps-toggle-group[data-filter="' + filterName + '"]');
      if (!group) return;
      group.querySelectorAll('.ps-toggle').forEach(function (b) {
        b.classList.toggle('ps-toggle-active', b.dataset.value === value);
      });
      if (!group.querySelector('.ps-toggle-active')) {
        group.querySelector('.ps-toggle[data-value=""]').classList.add('ps-toggle-active');
      }
    }
  }

  function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    const div = document.createElement('div');
    div.textContent = String(str);
    return div.innerHTML;
  }

})(Drupal, once);
