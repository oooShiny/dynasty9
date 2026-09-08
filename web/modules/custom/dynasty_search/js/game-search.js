/**
 * @file
 * Game Search: fetches /dynasty/search/games once, then does all
 * filtering/sorting/win-loss-record computation client-side.
 */

(function (Drupal, once) {
  'use strict';

  const DATA_URL = '/dynasty/search/games';
  const DEBOUNCE_MS = 300;

  Drupal.behaviors.gameSearch = {
    attach: function (context) {
      once('game-search-init', '#game-search-app', context).forEach(function (app) {
        initGameSearch(app);
      });
    }
  };

  function initGameSearch(app) {
    const filterToggle = app.querySelector('#gs-filter-toggle');
    const filterPanel = app.querySelector('#gs-filters-panel');
    if (filterToggle && filterPanel) {
      filterToggle.addEventListener('click', function () {
        filterPanel.classList.toggle('hidden');
        filterPanel.classList.toggle('block');
      });
    }

    const els = {
      tbody: app.querySelector('#gs-tbody'),
      thead: app.querySelector('thead'),
      count: app.querySelector('#gs-count'),
      activeFilters: app.querySelector('#gs-active-filters'),
      clearFilters: app.querySelector('#gs-clear-filters'),
      winloss: app.querySelector('#gs-winloss'),
      winpct: app.querySelector('#gs-winpct'),
      avgscore: app.querySelector('#gs-avgscore'),
      qbStats: app.querySelector('#gs-qb-stats'),
      opponent: app.querySelector('#gs-filter-opponent'),
      coach: app.querySelector('#gs-filter-coach'),
      patriotsHc: app.querySelector('#gs-filter-patriots-hc'),
      patriotsOc: app.querySelector('#gs-filter-patriots-oc'),
      patriotsDc: app.querySelector('#gs-filter-patriots-dc'),
      oppOc: app.querySelector('#gs-filter-opp-oc'),
      oppDc: app.querySelector('#gs-filter-opp-dc'),
      week: app.querySelector('#gs-filter-week'),
      qb: app.querySelector('#gs-filter-qb'),
      season: app.querySelector('#gs-filter-season'),
      reset: app.querySelector('#gs-reset'),
    };

    let games = [];
    let sortField = 'date';
    let sortDir = 'desc';
    let debounceTimer = null;
    let restoring = false;
    const sliders = {};

    fetch(DATA_URL)
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(function (data) {
        games = data;
        updateFilterOptions(getFilters());
        initRangeSliders();
        bindEvents();
        initToggleDefaults();
        restoreFromUrl();
        render();
      })
      .catch(function (err) {
        if (els.tbody) {
          els.tbody.innerHTML = '<tr><td colspan="16">Failed to load games: ' + escapeHtml(err.message) + '</td></tr>';
        }
      });

    // --- Filter option lists ---

    // Cross-filtering: each select's option list is recomputed from games
    // matching every OTHER active filter (excluding its own dimension, so
    // the current selection stays visible/editable) -- e.g. picking an
    // opponent narrows the Coach list to only coaches who actually faced
    // that opponent.
    function updateFilterOptions(f) {
      const withoutOpponent = games.filter(function (g) { return matches(g, f, 'opponent'); });
      const withoutCoach = games.filter(function (g) { return matches(g, f, 'coach'); });
      const withoutPatriotsHc = games.filter(function (g) { return matches(g, f, 'patriots_hc'); });
      const withoutPatriotsOc = games.filter(function (g) { return matches(g, f, 'patriots_oc'); });
      const withoutPatriotsDc = games.filter(function (g) { return matches(g, f, 'patriots_dc'); });
      const withoutOppOc = games.filter(function (g) { return matches(g, f, 'opp_oc'); });
      const withoutOppDc = games.filter(function (g) { return matches(g, f, 'opp_dc'); });
      const withoutSeason = games.filter(function (g) { return matches(g, f, 'season'); });
      const withoutWeek = games.filter(function (g) { return matches(g, f, 'week'); });
      const withoutQb = games.filter(function (g) { return matches(g, f, 'qb'); });

      const wasRestoring = restoring;
      restoring = true;
      fillSelect(els.opponent, uniqueSorted(withoutOpponent, function (g) { return g.opponent ? g.opponent.name : null; }));
      fillSelect(els.coach, uniqueSorted(withoutCoach, function (g) { return g.opposing_coach; }));
      fillSelect(els.patriotsHc, uniqueSorted(withoutPatriotsHc, function (g) { return g.patriots_hc; }));
      fillSelect(els.patriotsOc, uniqueSorted(withoutPatriotsOc, function (g) { return g.patriots_oc; }));
      fillSelect(els.patriotsDc, uniqueSorted(withoutPatriotsDc, function (g) { return g.patriots_dc; }));
      fillSelect(els.oppOc, uniqueSorted(withoutOppOc, function (g) { return g.opp_oc; }));
      fillSelect(els.oppDc, uniqueSorted(withoutOppDc, function (g) { return g.opp_dc; }));
      fillSelect(els.season, uniqueSorted(withoutSeason, function (g) { return String(g.season); }).sort(function (a, b) { return Number(b) - Number(a); }));
      fillSelect(els.week, uniqueWeeks(withoutWeek));
      fillSelect(els.qb, uniqueSorted(withoutQb, function (g) { return g.starting_qb; }));
      [els.opponent, els.coach, els.patriotsHc, els.patriotsOc, els.patriotsDc, els.oppOc, els.oppDc, els.season, els.week, els.qb].forEach(refreshSelect2);
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

    function uniqueSorted(rows, getter) {
      const set = new Set();
      rows.forEach(function (r) {
        const v = getter(r);
        if (v) set.add(v);
      });
      return Array.from(set).sort();
    }

    function uniqueWeeks(rows) {
      const map = new Map();
      rows.forEach(function (r) {
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
      sliders.patsScore = createRangeSlider('gs-slider-patriots-score', fieldRange(games, 'patriots_score'));
      sliders.oppScore = createRangeSlider('gs-slider-opponent-score', fieldRange(games, 'opponent_score'));
      sliders.diff = createRangeSlider('gs-slider-score-differential', fieldRange(games, 'score_differential'));
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
      [els.opponent, els.coach, els.patriotsHc, els.patriotsOc, els.patriotsDc, els.oppOc, els.oppDc, els.season, els.week, els.qb].forEach(function (select) {
        if (!select) return;
        select.addEventListener('change', onFilterChange);
        if (window.jQuery) {
          window.jQuery(select).on('change', onFilterChange);
        }
      });

      app.querySelectorAll('.gs-toggle-group').forEach(function (group) {
        group.querySelectorAll('.gs-toggle').forEach(function (btn) {
          btn.addEventListener('click', function () {
            group.querySelectorAll('.gs-toggle').forEach(function (b) { b.classList.remove('gs-toggle-active'); });
            btn.classList.add('gs-toggle-active');
            onFilterChange();
          });
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

      if (els.thead) {
        els.thead.querySelectorAll('th[data-sortable="1"]').forEach(function (th) {
          th.style.cursor = 'pointer';
          th.addEventListener('click', function () {
            const field = th.dataset.field;
            if (sortField === field) {
              sortDir = sortDir === 'asc' ? 'desc' : 'asc';
            } else {
              sortField = field;
              sortDir = 'desc';
            }
            render();
          });
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
      app.querySelectorAll('.gs-toggle-group').forEach(function (group) {
        group.querySelector('.gs-toggle[data-value=""]').classList.add('gs-toggle-active');
      });
    }

    function onFilterChange() {
      if (restoring) return;
      syncUrl();
      render();
    }

    function resetFilters() {
      [els.opponent, els.coach, els.patriotsHc, els.patriotsOc, els.patriotsDc, els.oppOc, els.oppDc, els.season, els.week, els.qb].forEach(function (select) {
        if (!select) return;
        Array.from(select.options).forEach(function (o) { o.selected = false; });
        refreshSelect2(select);
      });
      app.querySelectorAll('.gs-toggle-group').forEach(function (group) {
        group.querySelectorAll('.gs-toggle').forEach(function (b) { b.classList.remove('gs-toggle-active'); });
        group.querySelector('.gs-toggle[data-value=""]').classList.add('gs-toggle-active');
      });
      [sliders.patsScore, sliders.oppScore, sliders.diff].forEach(function (s) {
        if (s && s.el.noUiSlider) s.el.noUiSlider.set([s.range.min, s.range.max]);
      });
    }

    // --- Reading current filter state ---

    function selected(select) {
      return select ? Array.from(select.selectedOptions).map(function (o) { return o.value; }) : [];
    }

    function toggleValue(filterName) {
      const group = app.querySelector('.gs-toggle-group[data-filter="' + filterName + '"]');
      if (!group) return '';
      const active = group.querySelector('.gs-toggle-active');
      return active ? active.dataset.value : '';
    }

    function getFilters() {
      const pats = sliderRange(sliders.patsScore);
      const opp = sliderRange(sliders.oppScore);
      const diff = sliderRange(sliders.diff);
      return {
        opponent: selected(els.opponent),
        coach: selected(els.coach),
        patriotsHc: selected(els.patriotsHc),
        patriotsOc: selected(els.patriotsOc),
        patriotsDc: selected(els.patriotsDc),
        oppOc: selected(els.oppOc),
        oppDc: selected(els.oppDc),
        season: selected(els.season),
        week: selected(els.week),
        qb: selected(els.qb),
        result: toggleValue('result'),
        home_away: toggleValue('home_away'),
        after_bye: toggleValue('after_bye'),
        ot: toggleValue('ot'),
        playoff_game: toggleValue('playoff_game'),
        min_pats: pats[0],
        max_pats: pats[1],
        min_opp: opp[0],
        max_opp: opp[1],
        min_diff: diff[0],
        max_diff: diff[1],
      };
    }

    function matches(game, f, excludeField) {
      if (excludeField !== 'opponent' && f.opponent.length && (!game.opponent || f.opponent.indexOf(game.opponent.name) === -1)) return false;
      if (excludeField !== 'coach' && f.coach.length && f.coach.indexOf(game.opposing_coach) === -1) return false;
      if (excludeField !== 'patriots_hc' && f.patriotsHc.length && f.patriotsHc.indexOf(game.patriots_hc) === -1) return false;
      if (excludeField !== 'patriots_oc' && f.patriotsOc.length && f.patriotsOc.indexOf(game.patriots_oc) === -1) return false;
      if (excludeField !== 'patriots_dc' && f.patriotsDc.length && f.patriotsDc.indexOf(game.patriots_dc) === -1) return false;
      if (excludeField !== 'opp_oc' && f.oppOc.length && f.oppOc.indexOf(game.opp_oc) === -1) return false;
      if (excludeField !== 'opp_dc' && f.oppDc.length && f.oppDc.indexOf(game.opp_dc) === -1) return false;
      if (excludeField !== 'season' && f.season.length && f.season.indexOf(String(game.season)) === -1) return false;
      if (excludeField !== 'week' && f.week.length && (!game.week || f.week.indexOf(game.week.label) === -1)) return false;
      if (excludeField !== 'qb' && f.qb.length && (!game.starting_qb || f.qb.indexOf(game.starting_qb) === -1)) return false;
      if (f.result && game.result !== f.result) return false;
      if (f.home_away && game.home_away !== f.home_away) return false;
      if (f.after_bye !== '' && Boolean(game.after_bye) !== (f.after_bye === '1')) return false;
      if (f.ot !== '' && Boolean(game.ot) !== (f.ot === '1')) return false;
      if (f.playoff_game !== '' && Boolean(game.playoff_game) !== (f.playoff_game === '1')) return false;
      if (f.min_pats !== null && game.patriots_score < f.min_pats) return false;
      if (f.max_pats !== null && game.patriots_score > f.max_pats) return false;
      if (f.min_opp !== null && game.opponent_score < f.min_opp) return false;
      if (f.max_opp !== null && game.opponent_score > f.max_opp) return false;
      if (f.min_diff !== null && game.score_differential < f.min_diff) return false;
      if (f.max_diff !== null && game.score_differential > f.max_diff) return false;
      return true;
    }

    // --- Sorting ---

    function sortValue(game, field) {
      switch (field) {
        case 'opponent': return game.opponent ? game.opponent.name : '';
        case 'opp_coach': return game.opposing_coach || '';
        case 'date': return game.date || '';
        default: return game[field];
      }
    }

    function sortGames(rows) {
      const dir = sortDir === 'asc' ? 1 : -1;
      return rows.slice().sort(function (a, b) {
        const av = sortValue(a, sortField);
        const bv = sortValue(b, sortField);
        if (av === bv) return 0;
        if (av === null || av === undefined) return 1;
        if (bv === null || bv === undefined) return -1;
        return av > bv ? dir : -dir;
      });
    }

    // --- Render ---

    function render() {
      const filters = getFilters();
      const filtered = sortGames(games.filter(function (g) { return matches(g, filters); }));

      renderCount(filtered.length);
      renderActiveFilters(filters);
      renderRecord(filtered);
      renderTable(filtered);
      updateFilterOptions(filters);
    }

    function renderCount(n) {
      if (els.count) els.count.textContent = n.toLocaleString();
    }

    function hasActiveFilters(f) {
      return f.opponent.length > 0 || f.coach.length > 0 || f.season.length > 0 || f.week.length > 0 || f.qb.length > 0 || f.patriotsHc.length > 0 || f.patriotsOc.length > 0 || f.patriotsDc.length > 0 || f.oppOc.length > 0 || f.oppDc.length > 0 ||
        f.result !== '' || f.home_away !== '' || f.after_bye !== '' || f.ot !== '' || f.playoff_game !== '' ||
        f.min_pats !== null || f.max_pats !== null || f.min_opp !== null || f.max_opp !== null ||
        f.min_diff !== null || f.max_diff !== null;
    }

    function renderActiveFilters(f) {
      if (els.clearFilters) {
        els.clearFilters.classList.toggle('hidden', !hasActiveFilters(f));
      }
      if (!els.activeFilters) return;
      const chips = [];
      f.opponent.forEach(function (v) { chips.push(chip('opponent:' + v, 'Opponent', v)); });
      f.coach.forEach(function (v) { chips.push(chip('coach:' + v, 'Coach', v)); });
      f.patriotsHc.forEach(function (v) { chips.push(chip('patriots_hc:' + v, 'Patriots HC', v)); });
      f.patriotsOc.forEach(function (v) { chips.push(chip('patriots_oc:' + v, 'Patriots OC', v)); });
      f.patriotsDc.forEach(function (v) { chips.push(chip('patriots_dc:' + v, 'Patriots DC', v)); });
      f.oppOc.forEach(function (v) { chips.push(chip('opp_oc:' + v, 'Opp OC', v)); });
      f.oppDc.forEach(function (v) { chips.push(chip('opp_dc:' + v, 'Opp DC', v)); });
      f.season.forEach(function (v) { chips.push(chip('season:' + v, 'Season', v)); });
      f.week.forEach(function (v) { chips.push(chip('week:' + v, 'Week', v)); });
      f.qb.forEach(function (v) { chips.push(chip('qb:' + v, 'QB', v)); });
      if (f.result) chips.push(chip('result', 'Result', f.result));
      if (f.home_away) chips.push(chip('home_away', 'Location', f.home_away));
      if (f.after_bye !== '') chips.push(chip('after_bye', 'After Bye', (f.after_bye === '1' ? 'Yes' : 'No')));
      if (f.ot !== '') chips.push(chip('ot', 'OT', (f.ot === '1' ? 'Yes' : 'No')));
      if (f.playoff_game !== '') chips.push(chip('playoff_game', 'Playoff', (f.playoff_game === '1' ? 'Yes' : 'No')));
      els.activeFilters.innerHTML = chips.join('');
    }

    function chip(removeKey, label, filter) {
      return '<span class="bg-white border-2 border-red-pats gap-1 p-1 rounded-full">'
        + '<strong class="">' + escapeHtml(label) + ':</strong> ' + filter
        + '<button type="button" data-remove="' + escapeHtml(removeKey) + '" class="p-1 text-red-pats cursor-pointer">&times;</button>'
        + '</span>';
    }

    function removeFilter(key) {
      const [type, value] = key.split(/:(.*)/s);
      const multiMap = { opponent: els.opponent, coach: els.coach, season: els.season, week: els.week, qb: els.qb, patriots_hc: els.patriotsHc, patriots_oc: els.patriotsOc, patriots_dc: els.patriotsDc, opp_oc: els.oppOc, opp_dc: els.oppDc };
      if (multiMap[type]) {
        Array.from(multiMap[type].options).forEach(function (o) {
          if (o.value === value) o.selected = false;
        });
        refreshSelect2(multiMap[type]);
      } else {
        const group = app.querySelector('.gs-toggle-group[data-filter="' + type + '"]');
        if (group) {
          group.querySelectorAll('.gs-toggle').forEach(function (b) { b.classList.remove('gs-toggle-active'); });
          group.querySelector('.gs-toggle[data-value=""]').classList.add('gs-toggle-active');
        }
      }
    }

    function renderRecord(rows) {
      let wins = 0, losses = 0, ties = 0;
      let patsScore = 0, oppScore = 0;
      const qbStats = {};

      rows.forEach(function (g) {
        if (g.result === 'Win') wins++;
        else if (g.result === 'Loss') losses++;
        else ties++;
        patsScore += g.patriots_score || 0;
        oppScore += g.opponent_score || 0;

        const qb = g.starting_qb || '';
        if (qb) {
          if (!qbStats[qb]) qbStats[qb] = { attempts: 0, completions: 0, tds: 0, ints: 0 };
          qbStats[qb].attempts += g.brady_attempts || 0;
          qbStats[qb].completions += g.brady_completions || 0;
          qbStats[qb].tds += g.brady_tds || 0;
          qbStats[qb].ints += g.brady_ints || 0;
        }
      });

      const games = rows.length;
      if (els.winloss) els.winloss.textContent = wins + ' - ' + losses;
      if (els.winpct) els.winpct.textContent = games ? (wins / games).toFixed(3) : '0';
      if (els.avgscore) {
        els.avgscore.textContent = games ? Math.round(patsScore / games) + ' - ' + Math.round(oppScore / games) : '0 - 0';
      }
      renderQbStats(qbStats, games);
    }

    function renderQbStats(qbStats, games) {
      if (!els.qbStats) return;
      const qbs = Object.keys(qbStats);
      if (!qbs.length) {
        els.qbStats.innerHTML = '';
        return;
      }
      if (qbs.length > 1) {
        let rowsHtml = qbs.map(function (qb) {
          const s = qbStats[qb];
          return '<tr><td>' + escapeHtml(qb) + '</td><td>' + fmt(s.attempts) + '</td><td>' + fmt(s.completions) +
            '</td><td>' + fmt(s.tds) + '</td><td>' + fmt(s.ints) + '</td></tr>';
        }).join('');
        els.qbStats.innerHTML = '<details class="px-14 py-5"><summary class="p-2 text-xl patriots-white font-medium">QB Stats</summary>' +
          '<div class="bg-gray-100"><table class="table table-sm bg-white w-full"><thead class="bg-blue-pats text-white">' +
          '<tr><th>QB</th><th>ATT</th><th>COMP</th><th>TD</th><th>INT</th></tr></thead><tbody>' + rowsHtml + '</tbody></table></div></details>';
      } else {
        const qb = qbs[0];
        const s = qbStats[qb];
        const totalsHtml = ['attempts', 'completions', 'tds', 'ints'].map(function (key) {
          return '<div class="patriots p-2 w-1/4 md:!w-1/2"><div class="text-2xl text-center hidden md:!block">' +
            key.slice(0, 3).toUpperCase() + '</div><div class="text-xl md:!text-3xl text-center">' + fmt(s[key]) + '</div></div>';
        }).join('');
        const avgHtml = ['attempts', 'completions', 'tds', 'ints'].map(function (key) {
          const avg = games ? s[key] / games : 0;
          return '<div class="patriots p-2 w-1/4 md:!w-1/2"><div class="text-2xl text-center hidden md:!block">' +
            key.slice(0, 3).toUpperCase() + '</div><div class="text-xl md:!text-3xl text-center">' + fmt(avg) + '</div></div>';
        }).join('');
        els.qbStats.innerHTML = '<details class="px-14 py-5"><summary class="p-2 text-xl bg-red-pats text-white font-medium">' +
          escapeHtml(qb) + ' Stats</summary><div class="bg-white p-2 shadow-gray-500 shadow-md">' +
          '<h3 class="bg-blue-pats p-2 text-center text-lg text-white uppercase w-full">Totals</h3>' +
          '<div class="flex items-center justify-evenly gap-2">' + totalsHtml + '</div>' +
          '<h3 class="bg-blue-pats p-2 mt-4 text-center text-lg text-white uppercase w-full">Averages</h3>' +
          '<div class="flex items-center justify-evenly gap-2">' + avgHtml + '</div></div></details>';
      }
    }

    function fmt(n) {
      return Math.round(n).toLocaleString();
    }

    function renderTable(rows) {
      if (!els.tbody) return;
      if (!rows.length) {
        els.tbody.innerHTML = '<tr><td colspan="16" class="text-center p-5">No games match these filters.</td></tr>';
        return;
      }
      els.tbody.innerHTML = rows.map(function (g) {
        const rowClass = (g.result || '').toLowerCase();
        return '<tr class="' + rowClass + ' py-5 border bg-white">' +
          '<td class="p-5"><a href="' + escapeHtml(g.url) + '">' + escapeHtml(g.title) + '</a></td>' +
          '<td class="ml-5">' + fmt(g.patriots_score) + '</td>' +
          '<td class="pr-5">' + fmt(g.opponent_score) + '</td>' +
          '<td class="pr-5">' + (g.opponent ? '<a href="/node/' + g.opponent.nid + '">' + escapeHtml(g.opponent.name) + '</a>' : '') + '</td>' +
          '<td>' + escapeHtml(g.opposing_coach || '') + '</td>' +
          '<td>' + escapeHtml(g.result || '') + '</td>' +
          '<td class="tw-px-5">' + escapeHtml(g.home_away || '') + '</td>' +
          '<td class="pr-5">' + escapeHtml(g.month || '') + '</td>' +
          '<td class="border-r pr-5">' + escapeHtml(g.weekday || '') + '</td>' +
          '<td><div class="font-bold jersey text-blue-pats text-center text-xs uppercase">' + escapeHtml(lastName(g.starting_qb)) +
            '</div><div class="font-bold jersey-number-blue text-3xl">' + (g.qb_jersey_number != null ? g.qb_jersey_number : '') + '</div></td>' +
          '<td class="tw-px-5">' + fmt(g.brady_attempts) + '</td>' +
          '<td class="tw-pr-5">' + fmt(g.brady_completions) + '</td>' +
          '<td class="tw-pr-5">' + fmt(g.brady_yards) + '</td>' +
          '<td class="tw-pr-5">' + fmt(g.brady_tds) + '</td>' +
          '<td>' + fmt(g.brady_ints) + '</td>' +
          '<td>' + (g.passer_rating != null ? Number(g.passer_rating).toFixed(1) : '') + '</td>' +
          '</tr>';
      }).join('');
    }

    function lastName(name) {
      if (!name) return '';
      const parts = name.split(' ');
      return parts[parts.length - 1];
    }

    // --- URL state (shareable links) ---

    function syncUrl() {
      const f = getFilters();
      const params = new URLSearchParams();
      f.opponent.forEach(function (v) { params.append('opponent', v); });
      f.coach.forEach(function (v) { params.append('coach', v); });
      f.patriotsHc.forEach(function (v) { params.append('patriots_hc', v); });
      f.patriotsOc.forEach(function (v) { params.append('patriots_oc', v); });
      f.patriotsDc.forEach(function (v) { params.append('patriots_dc', v); });
      f.oppOc.forEach(function (v) { params.append('opp_oc', v); });
      f.oppDc.forEach(function (v) { params.append('opp_dc', v); });
      f.season.forEach(function (v) { params.append('season', v); });
      f.week.forEach(function (v) { params.append('week', v); });
      f.qb.forEach(function (v) { params.append('qb', v); });
      if (f.result) params.set('result', f.result);
      if (f.home_away) params.set('home_away', f.home_away);
      if (f.after_bye !== '') params.set('after_bye', f.after_bye);
      if (f.ot !== '') params.set('ot', f.ot);
      if (f.playoff_game !== '') params.set('playoff_game', f.playoff_game);
      if (f.min_pats !== null) params.set('min_pats', f.min_pats);
      if (f.max_pats !== null) params.set('max_pats', f.max_pats);
      if (f.min_opp !== null) params.set('min_opp', f.min_opp);
      if (f.max_opp !== null) params.set('max_opp', f.max_opp);
      if (f.min_diff !== null) params.set('min_diff', f.min_diff);
      if (f.max_diff !== null) params.set('max_diff', f.max_diff);
      const qs = params.toString();
      history.replaceState(null, '', qs ? '?' + qs : location.pathname);
    }

    function restoreFromUrl() {
      restoring = true;
      const params = new URLSearchParams(location.search);
      setMulti(els.opponent, params.getAll('opponent'));
      setMulti(els.coach, params.getAll('coach'));
      setMulti(els.patriotsHc, params.getAll('patriots_hc'));
      setMulti(els.patriotsOc, params.getAll('patriots_oc'));
      setMulti(els.patriotsDc, params.getAll('patriots_dc'));
      setMulti(els.oppOc, params.getAll('opp_oc'));
      setMulti(els.oppDc, params.getAll('opp_dc'));
      setMulti(els.season, params.getAll('season'));
      setMulti(els.week, params.getAll('week'));
      setMulti(els.qb, params.getAll('qb'));
      setToggle('result', params.get('result') || '');
      setToggle('home_away', params.get('home_away') || '');
      setToggle('after_bye', params.get('after_bye') || '');
      setToggle('ot', params.get('ot') || '');
      setToggle('playoff_game', params.get('playoff_game') || '');
      setSliderFromUrl(sliders.patsScore, params.get('min_pats'), params.get('max_pats'));
      setSliderFromUrl(sliders.oppScore, params.get('min_opp'), params.get('max_opp'));
      setSliderFromUrl(sliders.diff, params.get('min_diff'), params.get('max_diff'));
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
      const group = app.querySelector('.gs-toggle-group[data-filter="' + filterName + '"]');
      if (!group) return;
      group.querySelectorAll('.gs-toggle').forEach(function (b) {
        b.classList.toggle('gs-toggle-active', b.dataset.value === value);
      });
      if (!group.querySelector('.gs-toggle-active')) {
        group.querySelector('.gs-toggle[data-value=""]').classList.add('gs-toggle-active');
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
