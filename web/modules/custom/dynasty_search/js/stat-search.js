/**
 * @file
 * Stat Finder: fetches /dynasty/search/stats once -- one row per player per
 * game per quarter per stat category, PLUS one row per scoring/notable Play
 * (category "Scoring Play") -- then does all filtering, sorting,
 * grouping/summing, and pagination client-side.
 *
 * Results can be grouped: picking one or more "Group by" dimensions
 * (Player, Season, Game, Category, Quarter, Opponent, Home/Away, Result,
 * Scoring Team) collapses matching rows into summed totals per group --
 * e.g. "Julian Edelman's receiving totals by season", or "every stat line
 * with 100+ receiving yards", or "scoring plays by Scoring Team".
 */

(function (Drupal, once) {
  'use strict';

  const DATA_URL = '/dynasty/search/stats';
  const DEBOUNCE_MS = 300;
  const PER_PAGE = 50;

  // Shown in the results table while the (permanently-cached, but
  // sometimes slow on a cold cache) dataset is loading.
  const LOADING_ROW = '<tr><td class="p-5 text-center">' +
    '<span class="inline-block h-4 w-4 border-2 border-red-pats border-t-transparent rounded-full animate-spin align-middle mr-2"></span>' +
    'Loading&hellip;</td></tr>';

  // Numeric stat columns: [data key, slider element id, label]. `distance`
  // only applies to Scoring Play rows, the rest only to player stat lines
  // -- both kinds leave the other's columns blank/NULL, same idea.
  const STAT_FIELDS = [
    ['completions', 'ss-slider-completions', 'Comp'],
    ['attempts', 'ss-slider-attempts', 'Att'],
    ['pass_yards', 'ss-slider-pass-yards', 'Pass Yd'],
    ['pass_td', 'ss-slider-pass-td', 'Pass TD'],
    ['interceptions', 'ss-slider-interceptions', 'Int'],
    ['carries', 'ss-slider-carries', 'Car'],
    ['rush_yards', 'ss-slider-rush-yards', 'Rush Yd'],
    ['rush_td', 'ss-slider-rush-td', 'Rush TD'],
    ['targets', 'ss-slider-targets', 'Tgt'],
    ['receptions', 'ss-slider-receptions', 'Rec'],
    ['rec_yards', 'ss-slider-rec-yards', 'Rec Yd'],
    ['rec_td', 'ss-slider-rec-td', 'Rec TD'],
    ['distance', 'ss-slider-distance', 'Distance'],
  ];

  // Extra identity/descriptive columns for Scoring Play rows, always shown
  // in the ungrouped table (blank for player stat lines) but never summed.
  const PLAY_COLUMNS = [
    ['scoring_team', 'Scoring Team'],
    ['turnover', 'Turnover'],
    ['description', 'Description'],
  ];

  // "Group by" dimensions, in the fixed order they're always displayed,
  // regardless of the order they were toggled on in.
  const GROUPBY_DIMS = [
    { key: 'player', label: 'Player' },
    { key: 'season', label: 'Season' },
    { key: 'game', label: 'Game' },
    { key: 'category', label: 'Category' },
    { key: 'quarter', label: 'Quarter' },
    { key: 'opponent', label: 'Opponent' },
    { key: 'home_away', label: 'Home/Away' },
    { key: 'result', label: 'Result' },
    { key: 'scoring_team', label: 'Scoring Team' },
  ];

  Drupal.behaviors.statSearch = {
    attach: function (context) {
      once('stat-search-init', '#stat-search-app', context).forEach(function (app) {
        initStatSearch(app);
      });
    }
  };

  function initStatSearch(app) {
    const filterToggle = app.querySelector('#ss-filter-toggle');
    const filterPanel = app.querySelector('#ss-filters-panel');
    if (filterToggle && filterPanel) {
      filterToggle.addEventListener('click', function () {
        filterPanel.classList.toggle('hidden');
        filterPanel.classList.toggle('block');
      });
    }

    const els = {
      thead: app.querySelector('#ss-thead'),
      tbody: app.querySelector('#ss-tbody'),
      count: app.querySelector('#ss-count'),
      activeFilters: app.querySelector('#ss-active-filters'),
      clearFilters: app.querySelector('#ss-clear-filters'),
      groupby: app.querySelector('#ss-groupby'),
      player: app.querySelector('#ss-filter-player'),
      category: app.querySelector('#ss-filter-category'),
      quarter: app.querySelector('#ss-filter-quarter'),
      season: app.querySelector('#ss-filter-season'),
      week: app.querySelector('#ss-filter-week'),
      opponent: app.querySelector('#ss-filter-opponent'),
      scoringTeam: app.querySelector('#ss-filter-scoring-team'),
      reset: app.querySelector('#ss-reset'),
    };
    const MULTI_SELECTS = [els.player, els.category, els.quarter, els.season, els.week, els.opponent, els.scoringTeam];

    let rows = [];
    let sortField = null;
    let sortDir = 'desc';
    let currentPage = 0;
    let debounceTimer = null;
    let restoring = false;
    const sliders = {};
    const groupBy = new Set();

    if (els.tbody) {
      els.tbody.innerHTML = LOADING_ROW;
    }

    fetch(DATA_URL)
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(function (data) {
        rows = data.map(function (r) {
          r.display_player = r.player ? r.player.name : r.player_name;
          return r;
        });
        updateFilterOptions(getFilters());
        initRangeSliders();
        bindEvents();
        restoreFromUrl();
        render();
      })
      .catch(function (err) {
        if (els.tbody) {
          els.tbody.innerHTML = '<tr><td>Failed to load stats: ' + escapeHtml(err.message) + '</td></tr>';
        }
      });

    // --- Filter option lists ---

    // Cross-filtering: each select's option list is recomputed from rows
    // matching every OTHER active filter (excluding its own dimension), so
    // e.g. picking a season narrows Player to only players with a stat line
    // that season.
    function updateFilterOptions(f) {
      const withoutPlayer = rows.filter(function (r) { return matches(r, f, 'player'); });
      const withoutCategory = rows.filter(function (r) { return matches(r, f, 'category'); });
      const withoutQuarter = rows.filter(function (r) { return matches(r, f, 'quarter'); });
      const withoutSeason = rows.filter(function (r) { return matches(r, f, 'season'); });
      const withoutWeek = rows.filter(function (r) { return matches(r, f, 'week'); });
      const withoutOpponent = rows.filter(function (r) { return matches(r, f, 'opponent'); });
      const withoutScoringTeam = rows.filter(function (r) { return matches(r, f, 'scoringTeam'); });

      const wasRestoring = restoring;
      restoring = true;
      fillSelect(els.player, uniqueSorted(withoutPlayer, function (r) { return r.display_player; }));
      fillSelect(els.category, uniqueSorted(withoutCategory, function (r) { return r.category; }));
      fillSelect(els.quarter, sortQuarters(uniqueSorted(withoutQuarter, function (r) { return r.quarter; })));
      fillSelect(els.season, uniqueSorted(withoutSeason, function (r) { return String(r.season); }).sort(function (a, b) { return Number(b) - Number(a); }));
      fillSelect(els.week, uniqueWeeks(withoutWeek));
      fillSelect(els.opponent, uniqueSorted(withoutOpponent, function (r) { return r.opponent ? r.opponent.name : null; }));
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

    function refreshSelect2(select) {
      if (select && window.jQuery && window.jQuery(select).data('select2')) {
        window.jQuery(select).trigger('change');
      }
    }

    // --- Range sliders ---

    function initRangeSliders() {
      STAT_FIELDS.forEach(function (sf) {
        sliders[sf[0]] = createRangeSlider(sf[1], fieldRange(rows, sf[0]));
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
      MULTI_SELECTS.forEach(function (select) {
        if (!select) return;
        select.addEventListener('change', onFilterChange);
        if (window.jQuery) window.jQuery(select).on('change', onFilterChange);
      });

      app.querySelectorAll('.ss-toggle-group').forEach(function (group) {
        group.querySelectorAll('.ss-toggle').forEach(function (btn) {
          btn.addEventListener('click', function () {
            group.querySelectorAll('.ss-toggle').forEach(function (b) { b.classList.remove('ss-toggle-active'); });
            btn.classList.add('ss-toggle-active');
            onFilterChange();
          });
        });
      });
      initToggleDefaults();

      if (els.groupby) {
        els.groupby.querySelectorAll('.ss-groupby').forEach(function (btn) {
          btn.addEventListener('click', function () {
            const dim = btn.dataset.dimension;
            if (groupBy.has(dim)) {
              groupBy.delete(dim);
              btn.classList.remove('ss-groupby-active');
            }
            else {
              groupBy.add(dim);
              btn.classList.add('ss-groupby-active');
            }
            sortField = null;
            currentPage = 0;
            onFilterChange();
          });
        });
      }

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

      // The results table's <thead> is rebuilt on every render (its columns
      // depend on the current group-by selection), so click handlers can't
      // be bound directly to its <th> elements -- delegate from the
      // (stable) container instead.
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
            sortDir = 'desc';
          }
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
      app.querySelectorAll('.ss-toggle-group').forEach(function (group) {
        group.querySelector('.ss-toggle[data-value=""]').classList.add('ss-toggle-active');
      });
    }

    function onFilterChange() {
      if (restoring) return;
      currentPage = 0;
      syncUrl();
      render();
    }

    function resetFilters() {
      MULTI_SELECTS.forEach(function (select) {
        if (!select) return;
        Array.from(select.options).forEach(function (o) { o.selected = false; });
        refreshSelect2(select);
      });
      app.querySelectorAll('.ss-toggle-group').forEach(function (group) {
        group.querySelectorAll('.ss-toggle').forEach(function (b) { b.classList.remove('ss-toggle-active'); });
        group.querySelector('.ss-toggle[data-value=""]').classList.add('ss-toggle-active');
      });
      STAT_FIELDS.forEach(function (sf) {
        const s = sliders[sf[0]];
        if (s && s.el.noUiSlider) s.el.noUiSlider.set([s.range.min, s.range.max]);
      });
      if (els.groupby) {
        els.groupby.querySelectorAll('.ss-groupby').forEach(function (b) { b.classList.remove('ss-groupby-active'); });
      }
      groupBy.clear();
      sortField = null;
    }

    // --- Reading current filter state ---

    function selected(select) {
      return select ? Array.from(select.selectedOptions).map(function (o) { return o.value; }) : [];
    }

    function toggleValue(filterName) {
      const group = app.querySelector('.ss-toggle-group[data-filter="' + filterName + '"]');
      if (!group) return '';
      const active = group.querySelector('.ss-toggle-active');
      return active ? active.dataset.value : '';
    }

    function getFilters() {
      const stats = {};
      STAT_FIELDS.forEach(function (sf) {
        const range = sliderRange(sliders[sf[0]]);
        stats[sf[0]] = { min: range[0], max: range[1] };
      });
      return {
        player: selected(els.player),
        category: selected(els.category),
        quarter: selected(els.quarter),
        season: selected(els.season),
        week: selected(els.week),
        opponent: selected(els.opponent),
        scoringTeam: selected(els.scoringTeam),
        home_away: toggleValue('home_away'),
        result: toggleValue('result'),
        playoff_game: toggleValue('playoff_game'),
        turnover: toggleValue('turnover'),
        stats: stats,
      };
    }

    function matches(r, f, excludeField) {
      if (excludeField !== 'player' && f.player.length && f.player.indexOf(r.display_player) === -1) return false;
      if (excludeField !== 'category' && f.category.length && f.category.indexOf(r.category) === -1) return false;
      if (excludeField !== 'quarter' && f.quarter.length && f.quarter.indexOf(r.quarter) === -1) return false;
      if (excludeField !== 'season' && f.season.length && f.season.indexOf(String(r.season)) === -1) return false;
      if (excludeField !== 'week' && f.week.length && (!r.week || f.week.indexOf(r.week.label) === -1)) return false;
      if (excludeField !== 'opponent' && f.opponent.length && (!r.opponent || f.opponent.indexOf(r.opponent.name) === -1)) return false;
      if (excludeField !== 'scoringTeam' && f.scoringTeam.length && (!r.scoring_team || f.scoringTeam.indexOf(r.scoring_team) === -1)) return false;
      if (f.home_away && r.home_away !== f.home_away) return false;
      if (f.result && r.result !== f.result) return false;
      if (f.playoff_game !== '' && Boolean(r.playoff_game) !== (f.playoff_game === '1')) return false;
      // turnover is only meaningful for Scoring Play rows -- treat rows
      // where it's not applicable (NULL) as matching neither Yes nor No.
      if (f.turnover !== '' && (r.turnover === null || Boolean(r.turnover) !== (f.turnover === '1'))) return false;
      for (let i = 0; i < STAT_FIELDS.length; i++) {
        const field = STAT_FIELDS[i][0];
        const range = f.stats[field];
        if (range.min !== null && (r[field] === null || r[field] < range.min)) return false;
        if (range.max !== null && (r[field] === null || r[field] > range.max)) return false;
      }
      return true;
    }

    // --- Grouping ---

    function dimensionValue(r, dim) {
      switch (dim) {
        case 'player': return r.display_player || '';
        case 'season': return String(r.season);
        case 'game': return String(r.game_nid);
        case 'category': return r.category || '';
        case 'quarter': return r.quarter || '';
        case 'opponent': return r.opponent ? r.opponent.name : '';
        case 'home_away': return r.home_away || '';
        case 'result': return r.result || '';
        case 'scoring_team': return r.scoring_team || '';
        default: return '';
      }
    }

    function dimensionDisplay(r, dim) {
      if (dim === 'game') {
        return '<a href="' + escapeHtml(r.game_url) + '">' + escapeHtml(r.game_title) + '</a>';
      }
      const v = dimensionValue(r, dim);
      return escapeHtml(v);
    }

    function activeDims() {
      return GROUPBY_DIMS.filter(function (d) { return groupBy.has(d.key); });
    }

    function groupRows(list) {
      const dims = activeDims();
      const groups = new Map();
      list.forEach(function (r) {
        const key = dims.map(function (d) { return dimensionValue(r, d.key); }).join('');
        let g = groups.get(key);
        if (!g) {
          g = { key: key, sample: r, count: 0, stats: {} };
          STAT_FIELDS.forEach(function (sf) { g.stats[sf[0]] = 0; });
          groups.set(key, g);
        }
        g.count++;
        STAT_FIELDS.forEach(function (sf) {
          const v = r[sf[0]];
          if (v !== null && v !== undefined) g.stats[sf[0]] += v;
        });
      });
      return Array.from(groups.values());
    }

    // --- Sorting ---

    function sortRows(list, grouped) {
      if (!sortField) return list;
      const dir = sortDir === 'asc' ? 1 : -1;
      return list.slice().sort(function (a, b) {
        let av, bv;
        if (grouped) {
          const dim = activeDims().find(function (d) { return d.key === sortField; });
          if (dim) {
            av = dimensionValue(a.sample, dim.key);
            bv = dimensionValue(b.sample, dim.key);
          }
          else if (sortField === 'rows') {
            av = a.count;
            bv = b.count;
          }
          else {
            av = a.stats[sortField];
            bv = b.stats[sortField];
          }
        }
        else {
          switch (sortField) {
            case 'player': av = a.display_player; bv = b.display_player; break;
            case 'game': av = a.game_title; bv = b.game_title; break;
            case 'opponent': av = a.opponent ? a.opponent.name : ''; bv = b.opponent ? b.opponent.name : ''; break;
            default: av = a[sortField]; bv = b[sortField];
          }
        }
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
      const grouped = groupBy.size > 0;
      const displayRows = sortRows(grouped ? groupRows(filteredCache) : filteredCache, grouped);

      renderCount(displayRows.length, grouped);
      renderThead(grouped);
      renderTbody(displayRows, grouped);
    }

    function renderCount(n, grouped) {
      if (els.count) els.count.textContent = n.toLocaleString();
      const label = app.querySelector('#ss-summary h2');
      if (label) {
        label.innerHTML = 'Displaying <span class="text-red-pats"><span id="ss-count">' + n.toLocaleString() + '</span> ' +
          (grouped ? (n === 1 ? 'group' : 'groups') : (n === 1 ? 'stat line' : 'stat lines')) + '</span>';
      }
    }

    function hasActiveFilters(f) {
      if (f.player.length || f.category.length || f.quarter.length || f.season.length || f.week.length || f.opponent.length || f.scoringTeam.length) return true;
      if (f.home_away || f.result || f.playoff_game !== '' || f.turnover !== '') return true;
      return STAT_FIELDS.some(function (sf) {
        const r = f.stats[sf[0]];
        return r.min !== null || r.max !== null;
      });
    }

    function renderActiveFilters(f) {
      if (els.clearFilters) {
        els.clearFilters.classList.toggle('hidden', !hasActiveFilters(f));
      }
      if (!els.activeFilters) return;
      const chips = [];
      f.player.forEach(function (v) { chips.push(chip('player:' + v, 'Player', v)); });
      f.category.forEach(function (v) { chips.push(chip('category:' + v, 'Category', v)); });
      f.quarter.forEach(function (v) { chips.push(chip('quarter:' + v, 'Quarter', v)); });
      f.season.forEach(function (v) { chips.push(chip('season:' + v, 'Season', v)); });
      f.week.forEach(function (v) { chips.push(chip('week:' + v, 'Week', v)); });
      f.opponent.forEach(function (v) { chips.push(chip('opponent:' + v, 'Opponent', v)); });
      f.scoringTeam.forEach(function (v) { chips.push(chip('scoringTeam:' + v, 'Scoring Team', v)); });
      if (f.home_away) chips.push(chip('home_away', 'Location', f.home_away));
      if (f.result) chips.push(chip('result', 'Result', f.result));
      if (f.playoff_game !== '') chips.push(chip('playoff_game', 'Playoff', (f.playoff_game === '1' ? 'Yes' : 'No')));
      if (f.turnover !== '') chips.push(chip('turnover', 'Turnover', (f.turnover === '1' ? 'Yes' : 'No')));
      els.activeFilters.innerHTML = chips.join('');
    }

    function chip(removeKey, label, filter) {
      return '<span class="bg-white border-2 border-red-pats gap-1 p-1 rounded-full">'
        + '<strong class="">' + escapeHtml(label) + ':</strong> ' + escapeHtml(filter)
        + '<button type="button" data-remove="' + escapeHtml(removeKey) + '" class="p-1 text-red-pats cursor-pointer">&times;</button>'
        + '</span>';
    }

    function removeFilter(key) {
      const [type, value] = key.split(/:(.*)/s);
      const multiMap = { player: els.player, category: els.category, quarter: els.quarter, season: els.season, week: els.week, opponent: els.opponent, scoringTeam: els.scoringTeam };
      if (multiMap[type]) {
        Array.from(multiMap[type].options).forEach(function (o) {
          if (o.value === value) o.selected = false;
        });
        refreshSelect2(multiMap[type]);
      }
      else {
        const group = app.querySelector('.ss-toggle-group[data-filter="' + type + '"]');
        if (group) {
          group.querySelectorAll('.ss-toggle').forEach(function (b) { b.classList.remove('ss-toggle-active'); });
          group.querySelector('.ss-toggle[data-value=""]').classList.add('ss-toggle-active');
        }
      }
    }

    function sortArrow(field) {
      if (sortField !== field) return '';
      return sortDir === 'asc' ? ' ▲' : ' ▼';
    }

    function renderThead(grouped) {
      if (!els.thead) return;
      let headers = '';
      if (grouped) {
        activeDims().forEach(function (d) {
          headers += '<th data-field="' + d.key + '" class="cursor-pointer p-2">' + escapeHtml(d.label) + sortArrow(d.key) + '</th>';
        });
        headers += '<th data-field="rows" class="cursor-pointer p-2">Rows' + sortArrow('rows') + '</th>';
      }
      else {
        headers += '<th data-field="player" class="cursor-pointer p-2">Player' + sortArrow('player') + '</th>';
        headers += '<th data-field="game" class="cursor-pointer p-2">Game' + sortArrow('game') + '</th>';
        headers += '<th data-field="season" class="cursor-pointer p-2">Season' + sortArrow('season') + '</th>';
        headers += '<th data-field="quarter" class="cursor-pointer p-2">Qtr' + sortArrow('quarter') + '</th>';
        headers += '<th data-field="category" class="cursor-pointer p-2">Category' + sortArrow('category') + '</th>';
        PLAY_COLUMNS.forEach(function (pc) {
          headers += '<th data-field="' + pc[0] + '" class="cursor-pointer p-2">' + escapeHtml(pc[1]) + sortArrow(pc[0]) + '</th>';
        });
      }
      STAT_FIELDS.forEach(function (sf) {
        headers += '<th data-field="' + sf[0] + '" class="cursor-pointer p-2">' + sf[2] + sortArrow(sf[0]) + '</th>';
      });
      els.thead.innerHTML = '<tr>' + headers + '</tr>';
    }

    function renderTbody(displayRows, grouped) {
      if (!els.tbody) return;
      const colCount = (grouped ? activeDims().length + 1 : 5 + PLAY_COLUMNS.length) + STAT_FIELDS.length;
      const total = displayRows.length;
      const totalPages = Math.max(1, Math.ceil(total / PER_PAGE));
      if (currentPage >= totalPages) currentPage = totalPages - 1;
      if (currentPage < 0) currentPage = 0;
      const start = currentPage * PER_PAGE;
      const pageRows = displayRows.slice(start, start + PER_PAGE);

      if (!total) {
        els.tbody.innerHTML = '<tr><td colspan="' + colCount + '" class="text-center p-5">No stat lines match these filters.</td></tr>';
        renderPagination(0, 0);
        return;
      }

      els.tbody.innerHTML = pageRows.map(function (row) {
        let cells = '';
        if (grouped) {
          activeDims().forEach(function (d) {
            cells += '<td class="p-2">' + dimensionDisplay(row.sample, d.key) + '</td>';
          });
          cells += '<td class="p-2">' + row.count.toLocaleString() + '</td>';
          STAT_FIELDS.forEach(function (sf) {
            cells += '<td class="p-2">' + row.stats[sf[0]].toLocaleString() + '</td>';
          });
        }
        else {
          cells += '<td class="p-2">' + (row.player ? '<a href="/node/' + row.player.nid + '">' + escapeHtml(row.player.name) + '</a>' : escapeHtml(row.player_name)) + '</td>';
          cells += '<td class="p-2"><a href="' + escapeHtml(row.game_url) + '">' + escapeHtml(row.game_title) + '</a></td>';
          cells += '<td class="p-2">' + row.season + '</td>';
          cells += '<td class="p-2">' + escapeHtml(row.quarter) + '</td>';
          cells += '<td class="p-2">' + escapeHtml(row.category) + '</td>';
          cells += '<td class="p-2">' + escapeHtml(row.scoring_team) + '</td>';
          cells += '<td class="p-2">' + (row.turnover === null || row.turnover === undefined ? '' : (row.turnover ? 'Yes' : 'No')) + '</td>';
          cells += '<td class="p-2">' + escapeHtml(row.description) +
            (row.highlight_url ? ' <a href="' + escapeHtml(row.highlight_url) + '">&#9654; Watch</a>' : '') + '</td>';
          STAT_FIELDS.forEach(function (sf) {
            const v = row[sf[0]];
            cells += '<td class="p-2">' + (v === null || v === undefined ? '' : v.toLocaleString()) + '</td>';
          });
        }
        return '<tr class="border-b bg-white">' + cells + '</tr>';
      }).join('');

      renderPagination(total, totalPages);
    }

    function renderPagination(total, totalPages) {
      let pager = app.querySelector('#ss-pagination');
      if (!pager) {
        pager = document.createElement('div');
        pager.id = 'ss-pagination';
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
      f.player.forEach(function (v) { params.append('player', v); });
      f.category.forEach(function (v) { params.append('category', v); });
      f.quarter.forEach(function (v) { params.append('quarter', v); });
      f.season.forEach(function (v) { params.append('season', v); });
      f.week.forEach(function (v) { params.append('week', v); });
      f.opponent.forEach(function (v) { params.append('opponent', v); });
      f.scoringTeam.forEach(function (v) { params.append('scoring_team', v); });
      if (f.home_away) params.set('home_away', f.home_away);
      if (f.result) params.set('result', f.result);
      if (f.playoff_game !== '') params.set('playoff_game', f.playoff_game);
      if (f.turnover !== '') params.set('turnover', f.turnover);
      STAT_FIELDS.forEach(function (sf) {
        const r = f.stats[sf[0]];
        if (r.min !== null) params.set('min_' + sf[0], r.min);
        if (r.max !== null) params.set('max_' + sf[0], r.max);
      });
      groupBy.forEach(function (dim) { params.append('group_by', dim); });
      const qs = params.toString();
      history.replaceState(null, '', qs ? '?' + qs : location.pathname);
    }

    function restoreFromUrl() {
      restoring = true;
      const params = new URLSearchParams(location.search);
      setMulti(els.player, params.getAll('player'));
      setMulti(els.category, params.getAll('category'));
      setMulti(els.quarter, params.getAll('quarter'));
      setMulti(els.season, params.getAll('season'));
      setMulti(els.week, params.getAll('week'));
      setMulti(els.opponent, params.getAll('opponent'));
      setMulti(els.scoringTeam, params.getAll('scoring_team'));
      setToggle('home_away', params.get('home_away') || '');
      setToggle('result', params.get('result') || '');
      setToggle('playoff_game', params.get('playoff_game') || '');
      setToggle('turnover', params.get('turnover') || '');
      STAT_FIELDS.forEach(function (sf) {
        setSliderFromUrl(sliders[sf[0]], params.get('min_' + sf[0]), params.get('max_' + sf[0]));
      });
      params.getAll('group_by').forEach(function (dim) {
        if (!GROUPBY_DIMS.some(function (d) { return d.key === dim; })) return;
        groupBy.add(dim);
        const btn = els.groupby && els.groupby.querySelector('.ss-groupby[data-dimension="' + dim + '"]');
        if (btn) btn.classList.add('ss-groupby-active');
      });

      // Auto-expand collapsed filter groups that have an active filter from
      // the URL, so it isn't hidden behind a closed <details> on load.
      const gameTypeParams = ['home_away', 'result', 'playoff_game'];
      const gameTypeGroup = app.querySelector('#ss-group-game-type');
      if (gameTypeGroup && gameTypeParams.some(function (p) { return params.get(p); })) {
        gameTypeGroup.open = true;
      }
      openIfActive('ss-group-passing', ['completions', 'attempts', 'pass_yards', 'pass_td', 'interceptions']);
      openIfActive('ss-group-rushing', ['carries', 'rush_yards', 'rush_td']);
      openIfActive('ss-group-receiving', ['targets', 'receptions', 'rec_yards', 'rec_td']);
      const scoringPlayGroup = app.querySelector('#ss-group-scoring-play');
      if (scoringPlayGroup && (params.getAll('scoring_team').length || params.get('turnover') || params.get('min_distance') || params.get('max_distance'))) {
        scoringPlayGroup.open = true;
      }

      function openIfActive(groupId, fields) {
        const group = app.querySelector('#' + groupId);
        if (group && fields.some(function (f) { return params.get('min_' + f) || params.get('max_' + f); })) {
          group.open = true;
        }
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
      const group = app.querySelector('.ss-toggle-group[data-filter="' + filterName + '"]');
      if (!group) return;
      group.querySelectorAll('.ss-toggle').forEach(function (b) {
        b.classList.toggle('ss-toggle-active', b.dataset.value === value);
      });
      if (!group.querySelector('.ss-toggle-active')) {
        group.querySelector('.ss-toggle[data-value=""]').classList.add('ss-toggle-active');
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
