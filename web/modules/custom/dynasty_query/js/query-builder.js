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
        renderFactControls();
      })
      .catch(function () {
        showError('Could not load the query builder schema.');
      });

    els.fact.addEventListener('change', function () {
      els.filters.innerHTML = '';
      renderFactControls();
    });
    els.addFilter.addEventListener('click', addFilterRow);
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

    function addFilterRow() {
      const fact = currentFact();
      if (!fact) {
        return;
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

      loadFilterOptions(fact, fieldSelect.value, valueSelect);
    }

    function loadFilterOptions(fact, dimensionKey, valueSelect) {
      valueSelect.innerHTML = '<option disabled>Loading&hellip;</option>';
      const url = OPTIONS_URL + '?fact=' + encodeURIComponent(els.fact.value) + '&dimension=' + encodeURIComponent(dimensionKey);
      fetch(url)
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
      els.theadRow.innerHTML = '';
      columns.forEach(function (col) {
        const th = document.createElement('th');
        th.textContent = col.label;
        els.theadRow.appendChild(th);
      });

      els.tbody.innerHTML = '';
      if (!data.rows.length) {
        const tr = document.createElement('tr');
        const td = document.createElement('td');
        td.colSpan = columns.length || 1;
        td.className = 'p-5 text-center';
        td.textContent = 'No results.';
        tr.appendChild(td);
        els.tbody.appendChild(tr);
        return;
      }
      data.rows.forEach(function (row) {
        const tr = document.createElement('tr');
        columns.forEach(function (col) {
          const td = document.createElement('td');
          const value = row[col.key];
          td.textContent = (value === null || value === undefined) ? '' : value;
          tr.appendChild(td);
        });
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
  }
})(Drupal, once);
