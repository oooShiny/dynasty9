/**
 * @file
 * Play-by-Play Search: fetches /dynasty/search/play-by-play once (one row
 * per play, 1978-present), then does all filtering/searching/sorting/
 * pagination client-side. No grouping/summing here -- this is a raw play
 * log, browsed and searched, not summable stat columns (see Stat Finder
 * for that, over the 2000+ seasons that have per-player numbers). The
 * endpoint's response is normalized (games/players sent once, rows
 * reference them by id -- see SearchDataController::playByPlay()) and
 * denormalize() below expands it back into the flat per-row shape
 * (r.opponent, r.week, r.players[].name, etc.) everything past that
 * point expects, including play_type_label and `players` (role-tagged
 * Player nodes -- passer, rusher, tackler, etc.; see
 * SearchDataController::ROLE_FIELD_LABELS for the full role list).
 */

(function (Drupal, once) {
  'use strict';

  const DATA_URL = '/dynasty/search/play-by-play';
  const DEBOUNCE_MS = 300;
  const PER_PAGE = 50;

  // Shown in the results table while the (permanently-cached, but
  // sometimes slow on a cold cache) dataset is loading.
  const LOADING_ROW = '<tr><td class="p-5 text-center">' +
    '<span class="inline-block h-4 w-4 border-2 border-red-pats border-t-transparent rounded-full animate-spin align-middle mr-2"></span>' +
    'Loading&hellip;</td></tr>';

  const RANGE_FIELDS = [
    ['distance', 'pbp-slider-distance'],
    ['patriots_score', 'pbp-slider-patriots-score'],
    ['opponent_score', 'pbp-slider-opponent-score'],
  ];

  Drupal.behaviors.pbpSearch = {
    attach: function (context) {
      once('pbp-search-init', '#pbp-search-app', context).forEach(function (app) {
        initPbpSearch(app);
      });
    }
  };

  function initPbpSearch(app) {
    const filterToggle = app.querySelector('#pbp-filter-toggle');
    const filterPanel = app.querySelector('#pbp-filters-panel');
    if (filterToggle && filterPanel) {
      filterToggle.addEventListener('click', function () {
        filterPanel.classList.toggle('hidden');
        filterPanel.classList.toggle('block');
      });
    }

    const els = {
      thead: app.querySelector('#pbp-thead'),
      tbody: app.querySelector('#pbp-tbody'),
      count: app.querySelector('#pbp-count'),
      activeFilters: app.querySelector('#pbp-active-filters'),
      clearFilters: app.querySelector('#pbp-clear-filters'),
      search: app.querySelector('#pbp-search'),
      season: app.querySelector('#pbp-filter-season'),
      week: app.querySelector('#pbp-filter-week'),
      opponent: app.querySelector('#pbp-filter-opponent'),
      quarter: app.querySelector('#pbp-filter-quarter'),
      down: app.querySelector('#pbp-filter-down'),
      playType: app.querySelector('#pbp-filter-play-type'),
      player: app.querySelector('#pbp-filter-player'),
      scoringTeam: app.querySelector('#pbp-filter-scoring-team'),
      reset: app.querySelector('#pbp-reset'),
    };
    const MULTI_SELECTS = [els.season, els.week, els.opponent, els.quarter, els.down, els.playType, els.player, els.scoringTeam];

    let rows = [];
    let sortField = null;
    let sortDir = 'asc';
    let currentPage = 0;
    let debounceTimer = null;
    let restoring = false;
    const sliders = {};

    if (els.tbody) {
      els.tbody.innerHTML = LOADING_ROW;
    }

    fetch(DATA_URL)
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(function (data) {
        rows = denormalize(data);
        updateFilterOptions(getFilters());
        initRangeSliders();
        bindEvents();
        restoreFromUrl();
        render();
      })
      .catch(function (err) {
        if (els.tbody) {
          els.tbody.innerHTML = '<tr><td>Failed to load plays: ' + escapeHtml(err.message) + '</td></tr>';
        }
      });

    // --- Data normalization ---

    // The endpoint sends `{games, players, play_type_labels, rows}`: each
    // row references its game by `game_nid` and its players by nid,
    // rather than repeating a game's title/url/season/week/opponent on
    // every one of its ~380 plays or a player's name on every role they
    // appear in (see SearchDataController::playByPlay()'s normalization
    // comments -- this keeps that endpoint inside production's PHP-FPM
    // memory budget at ~135,000 rows). Denormalizing once here, right
    // after fetch, means every filter/sort/render function below can
    // keep reading r.opponent/r.week/r.players[].name/etc. directly, the
    // same shape this file used before the endpoint was normalized.
    function denormalize(data) {
      const games = data.games || {};
      const playerNames = data.players || {};
      const playTypeLabels = data.play_type_labels || {};
      return (data.rows || []).map(function (r) {
        const g = games[r.game_nid] || {};
        return Object.assign({}, r, {
          game_title: g.title,
          game_url: g.url,
          season: g.season,
          week: g.week,
          opponent: g.opponent,
          home_away: g.home_away,
          playoff_game: g.playoff_game,
          result: g.result,
          play_type_label: playTypeLabels[r.play_type] || r.play_type,
          player: (r.player != null) ? { nid: r.player, name: playerNames[r.player] } : null,
          players: (r.players || []).map(function (p) {
            return { nid: p[0], name: playerNames[p[0]], role: p[1] };
          }),
        });
      });
    }

    // --- Filter option lists ---

    function updateFilterOptions(f) {
      const withoutSeason = rows.filter(function (r) { return matches(r, f, 'season'); });
      const withoutWeek = rows.filter(function (r) { return matches(r, f, 'week'); });
      const withoutOpponent = rows.filter(function (r) { return matches(r, f, 'opponent'); });
      const withoutQuarter = rows.filter(function (r) { return matches(r, f, 'quarter'); });
      const withoutDown = rows.filter(function (r) { return matches(r, f, 'down'); });
      const withoutPlayType = rows.filter(function (r) { return matches(r, f, 'playType'); });
      const withoutPlayer = rows.filter(function (r) { return matches(r, f, 'player'); });
      const withoutScoringTeam = rows.filter(function (r) { return matches(r, f, 'scoringTeam'); });

      const wasRestoring = restoring;
      restoring = true;
      fillSelect(els.season, uniqueSorted(withoutSeason, function (r) { return String(r.season); }).sort(function (a, b) { return Number(b) - Number(a); }));
      fillSelect(els.week, uniqueWeeks(withoutWeek));
      fillSelect(els.opponent, uniqueSorted(withoutOpponent, function (r) { return r.opponent ? r.opponent.name : null; }));
      fillSelect(els.quarter, sortQuarters(uniqueSorted(withoutQuarter, function (r) { return r.quarter; })));
      fillSelect(els.down, uniqueSorted(withoutDown, function (r) { return r.down ? String(r.down) : null; }).sort(function (a, b) { return Number(a) - Number(b); }));
      fillSelect(els.playType, uniqueSorted(withoutPlayType, function (r) { return r.play_type_label; }));
      fillSelect(els.player, uniquePlayers(withoutPlayer));
      fillSelect(els.scoringTeam, uniqueSorted(withoutScoringTeam, function (r) { return r.scoring_team; }));
      MULTI_SELECTS.forEach(refreshSelect2);
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

    function uniqueSorted(list, getter) {
      const set = new Set();
      list.forEach(function (r) {
        const v = getter(r);
        if (v) set.add(v);
      });
      return Array.from(set).sort();
    }

    function sortQuarters(values) {
      const order = ['Q1', 'Q2', 'Q3', 'Q4', 'OT'];
      return values.slice().sort(function (a, b) { return order.indexOf(a) - order.indexOf(b); });
    }

    function uniqueWeeks(list) {
      const map = new Map();
      list.forEach(function (r) {
        if (r.week) map.set(r.week.label, r.week.weight);
      });
      return Array.from(map.entries())
        .sort(function (a, b) { return a[1] - b[1]; })
        .map(function (e) { return e[0]; });
    }

    // Distinct player names across every role (passer, rusher, tackler,
    // etc.) a row's `players` array can carry -- see
    // SearchDataController::ROLE_FIELD_LABELS. A player filtered on
    // matches a row regardless of which role they appear in on it.
    function uniquePlayers(list) {
      const set = new Set();
      list.forEach(function (r) {
        (r.players || []).forEach(function (p) { set.add(p.name); });
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
      RANGE_FIELDS.forEach(function (rf) {
        sliders[rf[0]] = createRangeSlider(rf[1], fieldRange(rows, rf[0]));
      });
    }

    function fieldRange(list, field) {
      let min = Infinity, max = -Infinity;
      list.forEach(function (r) {
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

      MULTI_SELECTS.forEach(function (select) {
        if (!select) return;
        select.addEventListener('change', onFilterChange);
        if (window.jQuery) window.jQuery(select).on('change', onFilterChange);
      });

      app.querySelectorAll('.pbp-toggle-group').forEach(function (group) {
        group.querySelectorAll('.pbp-toggle').forEach(function (btn) {
          btn.addEventListener('click', function () {
            group.querySelectorAll('.pbp-toggle').forEach(function (b) { b.classList.remove('pbp-toggle-active'); });
            btn.classList.add('pbp-toggle-active');
            onFilterChange();
          });
        });
      });
      initToggleDefaults();

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

      // The thead is rebuilt on every render (sort arrows), so delegate
      // from the stable container instead of binding <th> directly.
      if (els.thead) {
        els.thead.addEventListener('click', function (e) {
          const th = e.target.closest('th[data-field]');
          if (!th) return;
          const field = th.dataset.field;
          if (sortField === field) {
            sortDir = sortDir === 'asc' ? 'desc' : 'asc';
          }
          else {
            sortField = field;
            sortDir = 'asc';
          }
          currentPage = 0;
          renderResults();
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
    }

    function initToggleDefaults() {
      app.querySelectorAll('.pbp-toggle-group').forEach(function (group) {
        group.querySelector('.pbp-toggle[data-value=""]').classList.add('pbp-toggle-active');
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
      MULTI_SELECTS.forEach(function (select) {
        if (!select) return;
        Array.from(select.options).forEach(function (o) { o.selected = false; });
        refreshSelect2(select);
      });
      app.querySelectorAll('.pbp-toggle-group').forEach(function (group) {
        group.querySelectorAll('.pbp-toggle').forEach(function (b) { b.classList.remove('pbp-toggle-active'); });
        group.querySelector('.pbp-toggle[data-value=""]').classList.add('pbp-toggle-active');
      });
      RANGE_FIELDS.forEach(function (rf) {
        const s = sliders[rf[0]];
        if (s && s.el.noUiSlider) s.el.noUiSlider.set([s.range.min, s.range.max]);
      });
      sortField = null;
    }

    // --- Reading current filter state ---

    function selected(select) {
      return select ? Array.from(select.selectedOptions).map(function (o) { return o.value; }) : [];
    }

    function toggleValue(filterName) {
      const group = app.querySelector('.pbp-toggle-group[data-filter="' + filterName + '"]');
      if (!group) return '';
      const active = group.querySelector('.pbp-toggle-active');
      return active ? active.dataset.value : '';
    }

    function getFilters() {
      const ranges = {};
      RANGE_FIELDS.forEach(function (rf) {
        const range = sliderRange(sliders[rf[0]]);
        ranges[rf[0]] = { min: range[0], max: range[1] };
      });
      return {
        q: els.search ? els.search.value.trim().toLowerCase() : '',
        season: selected(els.season),
        week: selected(els.week),
        opponent: selected(els.opponent),
        quarter: selected(els.quarter),
        down: selected(els.down),
        playType: selected(els.playType),
        player: selected(els.player),
        scoringTeam: selected(els.scoringTeam),
        home_away: toggleValue('home_away'),
        result: toggleValue('result'),
        playoff_game: toggleValue('playoff_game'),
        scoring_play: toggleValue('scoring_play'),
        ranges: ranges,
      };
    }

    function matches(r, f, excludeField) {
      if (f.q) {
        const haystack = ((r.detail || '') + ' ' + (r.location || '')).toLowerCase();
        if (haystack.indexOf(f.q) === -1) return false;
      }
      if (excludeField !== 'season' && f.season.length && f.season.indexOf(String(r.season)) === -1) return false;
      if (excludeField !== 'week' && f.week.length && (!r.week || f.week.indexOf(r.week.label) === -1)) return false;
      if (excludeField !== 'opponent' && f.opponent.length && (!r.opponent || f.opponent.indexOf(r.opponent.name) === -1)) return false;
      if (excludeField !== 'quarter' && f.quarter.length && f.quarter.indexOf(r.quarter) === -1) return false;
      if (excludeField !== 'down' && f.down.length && f.down.indexOf(r.down ? String(r.down) : '') === -1) return false;
      if (excludeField !== 'playType' && f.playType.length && f.playType.indexOf(r.play_type_label) === -1) return false;
      if (excludeField !== 'player' && f.player.length && !(r.players || []).some(function (p) { return f.player.indexOf(p.name) !== -1; })) return false;
      if (excludeField !== 'scoringTeam' && f.scoringTeam.length && (!r.scoring_team || f.scoringTeam.indexOf(r.scoring_team) === -1)) return false;
      if (f.home_away && r.home_away !== f.home_away) return false;
      if (f.result && r.result !== f.result) return false;
      if (f.playoff_game !== '' && Boolean(r.playoff_game) !== (f.playoff_game === '1')) return false;
      if (f.scoring_play !== '' && Boolean(r.scoring_play) !== (f.scoring_play === '1')) return false;
      for (let i = 0; i < RANGE_FIELDS.length; i++) {
        const field = RANGE_FIELDS[i][0];
        const range = f.ranges[field];
        if (range.min !== null && (r[field] === null || r[field] < range.min)) return false;
        if (range.max !== null && (r[field] === null || r[field] > range.max)) return false;
      }
      return true;
    }

    // --- Sorting ---

    function sortValue(r, field) {
      switch (field) {
        case 'opponent': return r.opponent ? r.opponent.name : '';
        case 'game': return r.game_title || '';
        default: return r[field];
      }
    }

    function sortRows(list) {
      if (!sortField) return list;
      const dir = sortDir === 'asc' ? 1 : -1;
      return list.slice().sort(function (a, b) {
        const av = sortValue(a, sortField);
        const bv = sortValue(b, sortField);
        if (av === bv) return 0;
        if (av === null || av === undefined) return 1;
        if (bv === null || bv === undefined) return -1;
        return av > bv ? dir : -dir;
      });
    }

    // --- Render ---

    let filteredCache = [];

    function render() {
      const f = getFilters();
      filteredCache = rows.filter(function (r) { return matches(r, f); });
      renderActiveFilters(f);
      renderResults();
      updateFilterOptions(f);
    }

    function renderResults() {
      const displayRows = sortRows(filteredCache);
      renderCount(displayRows.length);
      renderThead();
      renderTbody(displayRows);
    }

    function renderCount(n) {
      if (els.count) els.count.textContent = n.toLocaleString();
    }

    function hasActiveFilters(f) {
      if (f.q || f.season.length || f.week.length || f.opponent.length || f.quarter.length || f.down.length || f.playType.length || f.player.length || f.scoringTeam.length) return true;
      if (f.home_away || f.result || f.playoff_game !== '' || f.scoring_play !== '') return true;
      return RANGE_FIELDS.some(function (rf) {
        const r = f.ranges[rf[0]];
        return r.min !== null || r.max !== null;
      });
    }

    function renderActiveFilters(f) {
      if (els.clearFilters) {
        els.clearFilters.classList.toggle('hidden', !hasActiveFilters(f));
      }
      if (!els.activeFilters) return;
      const chips = [];
      if (f.q) chips.push(chip('q', 'Search', f.q));
      f.season.forEach(function (v) { chips.push(chip('season:' + v, 'Season', v)); });
      f.week.forEach(function (v) { chips.push(chip('week:' + v, 'Week', v)); });
      f.opponent.forEach(function (v) { chips.push(chip('opponent:' + v, 'Opponent', v)); });
      f.quarter.forEach(function (v) { chips.push(chip('quarter:' + v, 'Quarter', v)); });
      f.down.forEach(function (v) { chips.push(chip('down:' + v, 'Down', v)); });
      f.playType.forEach(function (v) { chips.push(chip('playType:' + v, 'Play Type', v)); });
      f.player.forEach(function (v) { chips.push(chip('player:' + v, 'Player', v)); });
      f.scoringTeam.forEach(function (v) { chips.push(chip('scoringTeam:' + v, 'Scoring Team', v)); });
      if (f.home_away) chips.push(chip('home_away', 'Location', f.home_away));
      if (f.result) chips.push(chip('result', 'Result', f.result));
      if (f.playoff_game !== '') chips.push(chip('playoff_game', 'Playoff', (f.playoff_game === '1' ? 'Yes' : 'No')));
      if (f.scoring_play !== '') chips.push(chip('scoring_play', 'Scoring Play', (f.scoring_play === '1' ? 'Yes' : 'No')));
      els.activeFilters.innerHTML = chips.join('');
    }

    function chip(removeKey, label, filter) {
      return '<span class="bg-white border-2 border-red-pats gap-1 p-1 rounded-full">'
        + '<strong class="">' + escapeHtml(label) + ':</strong> ' + escapeHtml(filter)
        + '<button type="button" data-remove="' + escapeHtml(removeKey) + '" class="p-1 text-red-pats cursor-pointer">&times;</button>'
        + '</span>';
    }

    function removeFilter(key) {
      if (key === 'q') {
        if (els.search) els.search.value = '';
        return;
      }
      const [type, value] = key.split(/:(.*)/s);
      const map = { season: els.season, week: els.week, opponent: els.opponent, quarter: els.quarter, down: els.down, playType: els.playType, player: els.player, scoringTeam: els.scoringTeam };
      if (map[type]) {
        Array.from(map[type].options).forEach(function (o) {
          if (o.value === value) o.selected = false;
        });
        refreshSelect2(map[type]);
      }
      else {
        const group = app.querySelector('.pbp-toggle-group[data-filter="' + type + '"]');
        if (group) {
          group.querySelectorAll('.pbp-toggle').forEach(function (b) { b.classList.remove('pbp-toggle-active'); });
          group.querySelector('.pbp-toggle[data-value=""]').classList.add('pbp-toggle-active');
        }
      }
    }

    function sortArrow(field) {
      if (sortField !== field) return '';
      return sortDir === 'asc' ? ' ▲' : ' ▼';
    }

    const COLUMN_COUNT = 12;

    function renderThead() {
      if (!els.thead) return;
      const cols = [
        ['season', 'Season', true],
        ['week', 'Week', false],
        ['game', 'Game', true],
        ['quarter', 'Qtr', true],
        ['time', 'Time', false],
        ['down', 'Down', true],
        ['distance', 'Dist', true],
        ['location', 'Location', false],
        ['patriots_score', 'Pats', true],
        ['opponent_score', 'Opp', true],
        ['play_type_label', 'Type', true],
        ['players', 'Players', false],
      ];
      els.thead.innerHTML = '<tr>' + cols.map(function (c) {
        const sortable = c[2] ? ' cursor-pointer' : '';
        const field = c[2] ? ' data-field="' + c[0] + '"' : '';
        return '<th' + field + ' class="p-2' + sortable + '">' + c[1] + (c[2] ? sortArrow(c[0]) : '') + '</th>';
      }).join('') + '</tr>';
    }

    function renderTbody(displayRows) {
      if (!els.tbody) return;
      const total = displayRows.length;
      const totalPages = Math.max(1, Math.ceil(total / PER_PAGE));
      if (currentPage >= totalPages) currentPage = totalPages - 1;
      if (currentPage < 0) currentPage = 0;
      const start = currentPage * PER_PAGE;
      const pageRows = displayRows.slice(start, start + PER_PAGE);

      if (!total) {
        els.tbody.innerHTML = '<tr><td colspan="' + COLUMN_COUNT + '" class="text-center p-5">No plays match these filters.</td></tr>';
        renderPagination(0, 0);
        return;
      }

      els.tbody.innerHTML = pageRows.map(function (r) {
        return '<tr class="align-top">' +
          '<td colspan="' + COLUMN_COUNT + '" class="p-2 pt-3 font-bold">' + escapeHtml(r.detail) +
          (r.highlight_url ? ' <a href="' + escapeHtml(r.highlight_url) + '">&#9654; Watch</a>' : '') +
          (r.source_url ? ' <a href="' + escapeHtml(r.source_url) + '" target="_blank" rel="noopener" class="text-xs whitespace-nowrap">[source]</a>' : '') +
          '</td>' +
          '</tr>' +
          '<tr class="border-b bg-white align-top">' +
          '<td class="p-2">' + r.season + '</td>' +
          '<td class="p-2">' + escapeHtml(r.week ? r.week.label : '') + '</td>' +
          '<td class="p-2"><a href="' + escapeHtml(r.game_url) + '">' + escapeHtml(r.game_title) + '</a></td>' +
          '<td class="p-2">' + escapeHtml(r.quarter) + '</td>' +
          '<td class="p-2">' + escapeHtml(r.time) + '</td>' +
          '<td class="p-2">' + (r.down != null ? r.down : '') + '</td>' +
          '<td class="p-2">' + (r.distance != null ? r.distance : '') + '</td>' +
          '<td class="p-2">' + escapeHtml(r.location) + '</td>' +
          '<td class="p-2">' + (r.patriots_score != null ? r.patriots_score : '') + '</td>' +
          '<td class="p-2">' + (r.opponent_score != null ? r.opponent_score : '') + '</td>' +
          '<td class="p-2 whitespace-nowrap">' + escapeHtml(r.play_type_label) + '</td>' +
          '<td class="p-2 whitespace-nowrap">' + renderPlayers(r.players) + '</td>' +
          '</tr>';
      }).join('');

      renderPagination(total, totalPages);
    }

    function renderPagination(total, totalPages) {
      let pager = app.querySelector('#pbp-pagination');
      if (!pager) {
        pager = document.createElement('div');
        pager.id = 'pbp-pagination';
        pager.className = 'flex items-center gap-2 justify-center py-4';
        els.tbody.closest('.overflow-x-auto').after(pager);
        pager.addEventListener('click', function (e) {
          const btn = e.target.closest('[data-page]');
          if (!btn || btn.disabled) return;
          currentPage = parseInt(btn.dataset.page, 10);
          renderResults();
          app.scrollIntoView({ behavior: 'smooth' });
        });
      }
      if (totalPages <= 1) {
        pager.innerHTML = '';
        return;
      }
      pager.innerHTML =
        '<button class="px-4 py-2 border border-gray-300 bg-white rounded disabled:opacity-40" data-page="' +
        (currentPage - 1) + '" ' + (currentPage <= 0 ? 'disabled' : '') + '>&laquo;&laquo;</button>' +
        '<span class="px-2 py-2">Page ' + (currentPage + 1) + ' of ' + totalPages + '</span>' +
        '<button class="px-4 py-2 border border-gray-300 bg-white rounded disabled:opacity-40" data-page="' +
        (currentPage + 1) + '" ' + (currentPage >= totalPages - 1 ? 'disabled' : '') + '>&raquo;&raquo;</button>';
    }

    // --- URL state (shareable links) ---

    function syncUrl() {
      const f = getFilters();
      const params = new URLSearchParams();
      if (f.q) params.set('search', f.q);
      f.season.forEach(function (v) { params.append('season', v); });
      f.week.forEach(function (v) { params.append('week', v); });
      f.opponent.forEach(function (v) { params.append('opponent', v); });
      f.quarter.forEach(function (v) { params.append('quarter', v); });
      f.down.forEach(function (v) { params.append('down', v); });
      f.playType.forEach(function (v) { params.append('play_type', v); });
      f.player.forEach(function (v) { params.append('player', v); });
      f.scoringTeam.forEach(function (v) { params.append('scoring_team', v); });
      if (f.home_away) params.set('home_away', f.home_away);
      if (f.result) params.set('result', f.result);
      if (f.playoff_game !== '') params.set('playoff_game', f.playoff_game);
      if (f.scoring_play !== '') params.set('scoring_play', f.scoring_play);
      RANGE_FIELDS.forEach(function (rf) {
        const r = f.ranges[rf[0]];
        if (r.min !== null) params.set('min_' + rf[0], r.min);
        if (r.max !== null) params.set('max_' + rf[0], r.max);
      });
      const qs = params.toString();
      history.replaceState(null, '', qs ? '?' + qs : location.pathname);
    }

    function restoreFromUrl() {
      restoring = true;
      const params = new URLSearchParams(location.search);
      if (els.search && params.get('search')) els.search.value = params.get('search');
      setMulti(els.season, params.getAll('season'));
      setMulti(els.week, params.getAll('week'));
      setMulti(els.opponent, params.getAll('opponent'));
      setMulti(els.quarter, params.getAll('quarter'));
      setMulti(els.down, params.getAll('down'));
      setMulti(els.playType, params.getAll('play_type'));
      setMulti(els.player, params.getAll('player'));
      setMulti(els.scoringTeam, params.getAll('scoring_team'));
      setToggle('home_away', params.get('home_away') || '');
      setToggle('result', params.get('result') || '');
      setToggle('playoff_game', params.get('playoff_game') || '');
      setToggle('scoring_play', params.get('scoring_play') || '');
      RANGE_FIELDS.forEach(function (rf) {
        setSliderFromUrl(sliders[rf[0]], params.get('min_' + rf[0]), params.get('max_' + rf[0]));
      });

      const gameTypeParams = ['home_away', 'result', 'playoff_game', 'scoring_play'];
      const gameTypeGroup = app.querySelector('#pbp-group-game-type');
      if (gameTypeGroup && (gameTypeParams.some(function (p) { return params.get(p); }) || params.getAll('scoring_team').length)) {
        gameTypeGroup.open = true;
      }
      const playContextGroup = app.querySelector('#pbp-group-play-context');
      if (playContextGroup && RANGE_FIELDS.some(function (rf) { return params.get('min_' + rf[0]) || params.get('max_' + rf[0]); })) {
        playContextGroup.open = true;
      }

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
      const group = app.querySelector('.pbp-toggle-group[data-filter="' + filterName + '"]');
      if (!group) return;
      group.querySelectorAll('.pbp-toggle').forEach(function (b) {
        b.classList.toggle('pbp-toggle-active', b.dataset.value === value);
      });
      if (!group.querySelector('.pbp-toggle-active')) {
        group.querySelector('.pbp-toggle[data-value=""]').classList.add('pbp-toggle-active');
      }
    }
  }

  function renderPlayers(players) {
    if (!players || !players.length) return '';
    return players.map(function (p) {
      return '<div><span class="text-xs opacity-70">' + escapeHtml(p.role) + ':</span> ' + escapeHtml(p.name) + '</div>';
    }).join('');
  }

  function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    const div = document.createElement('div');
    div.textContent = String(str);
    return div.innerHTML;
  }

})(Drupal, once);
