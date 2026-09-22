/**
 * @file
 * Query Builder: fetches the fact-table whitelist from /dynasty/query/schema,
 * lets the visitor pick a data set, group-by dimensions, measures, and
 * filters, then POSTs to /dynasty/query/run and renders the result table.
 */

(function (Drupal, once) {
  'use strict';

  const SCHEMA_URL = '/dynasty/query/schema';
  const OPTIONS_URL = '/dynasty/query/options';
  const RUN_URL = '/dynasty/query/run';

  Drupal.behaviors.queryBuilder = {
    attach: function (context) {
      once('query-builder-init', '#query-builder-app', context).forEach(function (app) {
        initQueryBuilder(app);
      });
    }
  };

  function initQueryBuilder(app) {
    const els = {
      fact: app.querySelector('#qb-fact'),
      dimensions: app.querySelector('#qb-dimensions'),
      measures: app.querySelector('#qb-measures'),
      filters: app.querySelector('#qb-filters'),
      addFilter: app.querySelector('#qb-add-filter'),
      sort: app.querySelector('#qb-sort'),
      sortDir: app.querySelector('#qb-sort-dir'),
      limit: app.querySelector('#qb-limit'),
      run: app.querySelector('#qb-run'),
      error: app.querySelector('#qb-error'),
      theadRow: app.querySelector('#qb-thead-row'),
      tbody: app.querySelector('#qb-tbody'),
    };

    let schema = null;
    let filterRowCount = 0;

    fetch(SCHEMA_URL)
      .then(function (r) { return r.json(); })
      .then(function (data) {
        schema = data;
        Object.keys(schema).forEach(function (factKey) {
          const opt = document.createElement('option');
          opt.value = factKey;
          opt.textContent = schema[factKey].label;
          els.fact.appendChild(opt);
        });
        restoreFromUrl();
      })
      .catch(function () {
        showError('Could not load the query builder schema.');
      });

    els.fact.addEventListener('change', function () {
      els.filters.innerHTML = '';
      renderFactControls();
    });
    els.addFilter.addEventListener('click', function () { addFilterRow(); });
    els.run.addEventListener('click', runQuery);

    function currentFact() {
      return schema ? schema[els.fact.value] : null;
    }

    function renderFactControls() {
      const fact = currentFact();
      if (!fact) {
        return;
      }
      els.dimensions.innerHTML = '';
      fact.dimensions.forEach(function (dim) {
        els.dimensions.appendChild(buildCheckbox('qb-dim-', dim, updateSortOptions));
      });
      els.measures.innerHTML = '';
      fact.measures.forEach(function (measure) {
        els.measures.appendChild(buildCheckbox('qb-measure-', measure, updateSortOptions));
      });
      updateSortOptions();
    }

    function buildCheckbox(idPrefix, item, onChange) {
      const wrap = document.createElement('label');
      wrap.className = 'flex items-center gap-2 text-sm py-0.5';
      const input = document.createElement('input');
      input.type = 'checkbox';
      input.value = item.key;
      input.id = idPrefix + item.key;
      input.addEventListener('change', onChange);
      const span = document.createElement('span');
      span.textContent = item.label;
      wrap.appendChild(input);
      wrap.appendChild(span);
      return wrap;
    }

    function checkedValues(container) {
      return Array.from(container.querySelectorAll('input[type="checkbox"]:checked')).map(function (el) {
        return el.value;
      });
    }

    function updateSortOptions() {
      const fact = currentFact();
      if (!fact) {
        return;
      }
      const dimKeys = checkedValues(els.dimensions);
      const measureKeys = checkedValues(els.measures);
      const allItems = fact.dimensions.concat(fact.measures).filter(function (item) {
        return dimKeys.indexOf(item.key) !== -1 || measureKeys.indexOf(item.key) !== -1;
      });
      const previous = els.sort.value;
      els.sort.innerHTML = '<option value="">(none)</option>';
      allItems.forEach(function (item) {
        const opt = document.createElement('option');
        opt.value = item.key;
        opt.textContent = item.label;
        els.sort.appendChild(opt);
      });
      if (allItems.some(function (item) { return item.key === previous; })) {
        els.sort.value = previous;
      }
    }

    // `initial`, when passed (restoring a shared URL), is `{field, value}`
    // -- value pre-selection has to wait for loadFilterOptions()'s fetch to
    // populate the <option>s, so this returns that promise.
    function addFilterRow(initial) {
      const fact = currentFact();
      if (!fact) {
        return Promise.resolve();
      }
      const rowId = 'qb-filter-' + (filterRowCount++);
      const row = document.createElement('div');
      row.className = 'border border-gray-300 rounded p-2 bg-white text-black';
      row.dataset.rowId = rowId;

      const fieldSelect = document.createElement('select');
      fieldSelect.id = rowId + '-field';
      fieldSelect.name = rowId + '-field';
      fieldSelect.className = 'w-full mb-1 p-1 rounded border';
      fact.dimensions.forEach(function (dim) {
        const opt = document.createElement('option');
        opt.value = dim.key;
        opt.textContent = dim.label;
        fieldSelect.appendChild(opt);
      });
      if (initial && initial.field) {
        fieldSelect.value = initial.field;
      }

      const valueSelect = document.createElement('select');
      valueSelect.id = rowId + '-value';
      valueSelect.name = rowId + '-value';
      valueSelect.multiple = true;
      valueSelect.size = 4;
      valueSelect.className = 'w-full p-1 rounded border text-sm';

      const hint = document.createElement('div');
      hint.className = 'text-xs text-gray-500 mt-1';
      hint.textContent = 'Ctrl/Cmd-click to select multiple values.';

      const removeBtn = document.createElement('button');
      removeBtn.type = 'button';
      removeBtn.className = 'text-xs text-red-pats mt-1';
      removeBtn.textContent = 'Remove filter';
      removeBtn.addEventListener('click', function () {
        row.remove();
      });

      fieldSelect.addEventListener('change', function () {
        loadFilterOptions(fact, fieldSelect.value, valueSelect);
      });

      row.appendChild(fieldSelect);
      row.appendChild(valueSelect);
      row.appendChild(hint);
      row.appendChild(removeBtn);
      els.filters.appendChild(row);

      return loadFilterOptions(fact, fieldSelect.value, valueSelect).then(function () {
        if (initial && initial.value) {
          Array.from(valueSelect.options).forEach(function (o) {
            o.selected = initial.value.indexOf(o.value) !== -1;
          });
        }
      });
    }

    function loadFilterOptions(fact, dimensionKey, valueSelect) {
      valueSelect.innerHTML = '<option disabled>Loading&hellip;</option>';
      const url = OPTIONS_URL + '?fact=' + encodeURIComponent(els.fact.value) + '&dimension=' + encodeURIComponent(dimensionKey);
      return fetch(url)
        .then(function (r) { return r.json(); })
        .then(function (options) {
          valueSelect.innerHTML = '';
          if (!Array.isArray(options)) {
            return;
          }
          options.forEach(function (item) {
            const opt = document.createElement('option');
            opt.value = item.value;
            opt.textContent = item.label;
            valueSelect.appendChild(opt);
          });
        })
        .catch(function () {
          valueSelect.innerHTML = '<option disabled>Could not load options</option>';
        });
    }

    function collectFilters() {
      return Array.from(els.filters.children).map(function (row) {
        const field = row.querySelector('select').value;
        const valueSelect = row.querySelectorAll('select')[1];
        const values = Array.from(valueSelect.selectedOptions).map(function (o) { return o.value; });
        return { field: field, value: values };
      }).filter(function (f) { return f.value.length > 0; });
    }

    function runQuery() {
      const fact = els.fact.value;
      if (!fact) {
        return;
      }
      const dimensions = checkedValues(els.dimensions);
      const measures = checkedValues(els.measures);
      if (!dimensions.length && !measures.length) {
        showError('Pick at least one Group By field or Measure.');
        return;
      }
      hideError();

      const body = {
        fact: fact,
        dimensions: dimensions,
        measures: measures,
        filters: collectFilters(),
        limit: parseInt(els.limit.value, 10) || 50,
      };
      if (els.sort.value) {
        body.sort = { key: els.sort.value, direction: els.sortDir.value };
      }

      syncUrl(body);

      els.tbody.innerHTML = '<tr><td class="p-5 text-center">Running&hellip;</td></tr>';

      fetch(RUN_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      })
        .then(function (r) { return r.json().then(function (data) { return { ok: r.ok, data: data }; }); })
        .then(function (result) {
          if (!result.ok) {
            showError(result.data.error || 'Query failed.');
            els.tbody.innerHTML = '';
            return;
          }
          renderResults(result.data);
        })
        .catch(function () {
          showError('Query failed.');
        });
    }

    function renderResults(data) {
      const columns = data.columns.dimensions.concat(data.columns.measures);
      const colCount = 1 + columns.length + (data.has_highlights ? 1 : 0);

      els.theadRow.innerHTML = '';
      const numberTh = document.createElement('th');
      numberTh.textContent = '#';
      els.theadRow.appendChild(numberTh);
      columns.forEach(function (col) {
        const th = document.createElement('th');
        th.textContent = col.label;
        els.theadRow.appendChild(th);
      });
      if (data.has_highlights) {
        const th = document.createElement('th');
        th.textContent = 'Highlight';
        els.theadRow.appendChild(th);
      }

      els.tbody.innerHTML = '';
      if (!data.rows.length) {
        const tr = document.createElement('tr');
        const td = document.createElement('td');
        td.colSpan = colCount || 1;
        td.className = 'p-5 text-center';
        td.textContent = 'No results.';
        tr.appendChild(td);
        els.tbody.appendChild(tr);
        return;
      }
      data.rows.forEach(function (row, index) {
        const tr = document.createElement('tr');
        const numberTd = document.createElement('td');
        numberTd.textContent = index + 1;
        tr.appendChild(numberTd);
        columns.forEach(function (col) {
          const td = document.createElement('td');
          const value = row[col.key];
          // A resolved node dimension (player, opponent) comes back as
          // {label, url} rather than a plain scalar -- see
          // QueryDataController::resolveRowLabels() -- so it can link to
          // that node's own page instead of just showing its name.
          if (value && typeof value === 'object') {
            const a = document.createElement('a');
            a.href = value.url;
            a.textContent = value.label;
            td.appendChild(a);
          }
          else {
            td.textContent = (value === null || value === undefined) ? '' : value;
          }
          tr.appendChild(td);
        });
        if (data.has_highlights) {
          const td = document.createElement('td');
          if (row.highlight_url) {
            const a = document.createElement('a');
            a.href = row.highlight_url;
            a.textContent = '▶ Watch';
            td.appendChild(a);
          }
          tr.appendChild(td);
        }
        els.tbody.appendChild(tr);
      });
    }

    function showError(message) {
      els.error.textContent = message;
      els.error.classList.remove('hidden');
    }

    function hideError() {
      els.error.classList.add('hidden');
    }

    // --- URL state (shareable links) ---

    function syncUrl(body) {
      const params = new URLSearchParams();
      params.set('fact', body.fact);
      body.dimensions.forEach(function (v) { params.append('dim', v); });
      body.measures.forEach(function (v) { params.append('measure', v); });
      if (body.filters.length) {
        params.set('filters', JSON.stringify(body.filters));
      }
      if (body.sort) {
        params.set('sort', body.sort.key);
        params.set('sort_dir', body.sort.direction);
      }
      params.set('limit', body.limit);
      const qs = params.toString();
      history.replaceState(null, '', qs ? '?' + qs : location.pathname);
    }

    function restoreFromUrl() {
      const params = new URLSearchParams(location.search);
      const fact = params.get('fact');
      if (fact && schema[fact]) {
        els.fact.value = fact;
      }
      renderFactControls();

      if (!fact || !schema[fact]) {
        return;
      }

      checkAll(els.dimensions, params.getAll('dim'));
      checkAll(els.measures, params.getAll('measure'));
      updateSortOptions();

      if (params.get('sort')) {
        els.sort.value = params.get('sort');
        els.sortDir.value = params.get('sort_dir') || 'desc';
      }
      if (params.get('limit')) {
        els.limit.value = params.get('limit');
      }

      let filters = [];
      if (params.get('filters')) {
        try {
          filters = JSON.parse(params.get('filters'));
        }
        catch (e) {
          filters = [];
        }
      }

      Promise.all(filters.map(function (f) { return addFilterRow(f); })).then(function () {
        runQuery();
      });
    }

    function checkAll(container, keys) {
      Array.from(container.querySelectorAll('input[type="checkbox"]')).forEach(function (input) {
        if (keys.indexOf(input.value) !== -1) {
          input.checked = true;
        }
      });
    }
  }
})(Drupal, once);
