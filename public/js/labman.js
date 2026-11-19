/* LabMan front-end: filter box, live status polling, copy-SSH. No dependencies, no inline code (CSP-safe). */
(function () {
  'use strict';

  var body = document.body;
  var toastEl = document.getElementById('toast');
  var toastTimer = null;

  function toast(message) {
    if (!toastEl) return;
    toastEl.textContent = message;
    toastEl.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toastEl.hidden = true; }, 2600);
  }

  // ---------------------------------------------------------------- clipboard
  // navigator.clipboard only exists in secure contexts (https or localhost), so
  // over plain HTTP we fall back to a hidden textarea + execCommand('copy').
  function fallbackCopy(text) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.className = 'clip';
    ta.setAttribute('readonly', '');
    document.body.appendChild(ta);
    ta.focus();
    ta.select();
    ta.setSelectionRange(0, text.length);
    var ok = false;
    try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
    document.body.removeChild(ta);
    return ok;
  }

  function copyText(text) {
    if (window.isSecureContext && navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text).then(function () { return true; }, function () { return fallbackCopy(text); });
    }
    return Promise.resolve(fallbackCopy(text));
  }

  function copyRow(row) {
    var cmd = row.getAttribute('data-ssh');
    if (!cmd) return;
    copyText(cmd).then(function (ok) {
      if (ok) {
        toast('Copied: ' + cmd);
        row.classList.remove('flash-ok');
        void row.offsetWidth;
        row.classList.add('flash-ok');
      } else {
        toast('Could not copy automatically. Command: ' + cmd);
      }
    });
  }

  document.addEventListener('click', function (e) {
    var row = e.target.closest('.clickable');
    if (!row || e.target.closest('a, button, input, select, textarea')) return;

    if (row.hasAttribute('data-ssh')) {
      copyRow(row);
    } else if (row.hasAttribute('data-href')) {
      window.open(row.getAttribute('data-href'), '_blank', 'noopener,noreferrer');
    }
  });

  // Destructive buttons carry data-confirm (inline handlers are blocked by the CSP).
  document.addEventListener('click', function (e) {
    var btn = e.target.closest && e.target.closest('button[data-confirm]');
    if (btn && !window.confirm('Delete this? This cannot be undone.')) e.preventDefault();
  });

  document.addEventListener('keydown', function (e) {
    var row = e.target.closest && e.target.closest('.clickable[data-ssh], .clickable[data-href]');
    if (row && e.target === row && (e.key === 'Enter' || e.key === ' ')) {
      e.preventDefault();
      if (row.hasAttribute('data-ssh')) copyRow(row);
      else window.open(row.getAttribute('data-href'), '_blank', 'noopener,noreferrer');
    }
  });

  // ------------------------------------------------------------------ filter
  var filter = document.getElementById('filter');
  var noMatch = document.getElementById('no-match');

  function applyFilter() {
    if (!filter) return;
    var tokens = filter.value.toLowerCase().split(/\s+/).filter(Boolean);
    var visibleTotal = 0;

    document.querySelectorAll('.group').forEach(function (group) {
      var visible = 0;
      group.querySelectorAll('[data-filter]').forEach(function (row) {
        var hay = row.getAttribute('data-filter');
        var match = tokens.every(function (t) { return hay.indexOf(t) !== -1; });
        row.hidden = !match;
        if (match) visible++;
      });
      group.hidden = visible === 0;
      visibleTotal += visible;
    });

    if (noMatch) noMatch.hidden = visibleTotal !== 0 || tokens.length === 0;
  }

  if (filter) {
    filter.addEventListener('input', applyFilter);
    filter.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        filter.value = '';
        applyFilter();
        filter.blur();
      }
    });
  }

  document.addEventListener('keydown', function (e) {
    if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey || !filter) return;
    var t = e.target;
    var typing = t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable);
    if (typing) return;
    e.preventDefault();
    filter.focus();
    filter.select();
  });

  // ------------------------------------------------- icon name suggestions
  var iconInput = document.getElementById('icon');
  var iconList = document.getElementById('icon-list');
  if (iconInput && iconList && iconInput.getAttribute('data-suggest-url')) {
    var iconTimer = null;
    iconInput.addEventListener('input', function () {
      clearTimeout(iconTimer);
      var q = iconInput.value.trim();
      if (q.length < 2) return;
      iconTimer = setTimeout(function () {
        fetch(iconInput.getAttribute('data-suggest-url') + '?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
          .then(function (r) { return r.ok ? r.json() : { icons: [] }; })
          .then(function (data) {
            iconList.textContent = '';
            (data.icons || []).forEach(function (name) {
              var o = document.createElement('option');
              o.value = name;
              iconList.appendChild(o);
            });
          })
          .catch(function () {});
      }, 200);
    });
  }

  // ---------------------------------------------------------- live status
  var url = body.getAttribute('data-status-url');
  var interval = Math.max(5, parseFloat(body.getAttribute('data-interval')) || 30);
  var conn = document.getElementById('conn');

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
  }

  function renderChecks(container, checks, state) {
    container.textContent = '';
    if (!checks || !checks.length) {
      container.appendChild(el('span', 'chk', state === 'off' ? 'Not monitored: no checks are run.' : 'Waiting for the first check…'));
      return;
    }
    checks.forEach(function (c) {
      var parts = [c.label, c.detail];
      if (c.ms !== null && c.ms !== undefined) parts.push(c.ms + ' ms');
      container.appendChild(el('span', 'chk' + (c.ok ? '' : ' bad'), parts.join(' · ')));
    });
  }

  function applyItem(kind, item) {
    var row = document.querySelector('[data-kind="' + kind + '"][data-id="' + item.id + '"]');
    if (!row) return;
    row.setAttribute('data-state', item.state);
    var pill = row.querySelector('[data-pill]');
    if (pill) {
      var stale = item.stale && item.state !== 'off';
      var label = item.state === 'off' ? 'not monitored' : item.state;
      pill.className = 'pill pill-' + item.state + (stale ? ' is-stale' : '');
      pill.textContent = stale ? label + ' (stale)' : label;
      pill.title = item.checked_at ? 'Checked ' + new Date(item.checked_at).toLocaleString() : 'Not checked yet';
    }
    var detail = row.querySelector('[data-detail]');
    if (detail && row.classList.contains('tile')) {
      // Dashboard tiles show only the first thing that is wrong.
      var bad = (item.checks || []).filter(function (c) { return !c.ok; })[0];
      detail.textContent = bad ? bad.label + ' · ' + bad.detail : '';
    } else if (detail) {
      renderChecks(detail, item.checks, item.state);
    }
  }

  function applySummary(kind, counts) {
    ['up', 'degraded', 'down', 'unknown'].forEach(function (state) {
      var n = document.querySelector('[data-count="' + kind + ':' + state + '"]');
      if (n) n.textContent = counts[state];
    });
    updateHero();
  }

  // The dashboard headline follows the counters the live update keeps current.
  function updateHero() {
    var hero = document.getElementById('hero');
    if (!hero) return;
    var totals = { up: 0, degraded: 0, down: 0, unknown: 0 };
    document.querySelectorAll('[data-count]').forEach(function (n) {
      var state = n.getAttribute('data-count').split(':')[1];
      if (state in totals) totals[state] += parseInt(n.textContent, 10) || 0;
    });
    Object.keys(totals).forEach(function (state) {
      var t = document.querySelector('[data-total="' + state + '"]');
      if (t) t.textContent = totals[state];
    });
    var trouble = totals.down + totals.degraded;
    var watched = totals.up + totals.degraded + totals.down + totals.unknown;
    var headline = document.getElementById('headline');
    if (headline) {
      headline.textContent = watched === 0 ? 'Nothing is being monitored yet'
        : trouble === 0 ? 'All systems operational'
        : trouble === 1 ? '1 item needs attention' : trouble + ' items need attention';
    }
    var tone = (watched === 0 || totals.unknown === watched) ? 'unknown' : totals.down > 0 ? 'down' : totals.degraded > 0 ? 'degraded' : 'up';
    hero.className = 'hero hero-' + tone;
  }

  // An icon that fails to load (not in the library) leaves the letter avatar showing.
  document.addEventListener('error', function (e) {
    var img = e.target;
    if (img && img.classList && img.classList.contains('ico-img')) img.hidden = true;
  }, true);

  function tick() {
    if (!url) return;
    if (document.hidden) { schedule(); return; }

    fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) {
        if (r.status === 401 || r.status === 403) { window.location.reload(); throw new Error('auth'); }
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(function (data) {
        if (conn) conn.hidden = true;
        ['services', 'servers'].forEach(function (kind) {
          (data[kind] || []).forEach(function (item) { applyItem(kind, item); });
          if (data.summary && data.summary[kind]) applySummary(kind, data.summary[kind]);
        });
        if (data.interval) interval = Math.max(5, parseFloat(data.interval));
      })
      .catch(function (err) {
        if (err && err.message === 'auth') return;
        if (conn) conn.hidden = false;
      })
      .then(schedule);
  }

  function schedule() { setTimeout(tick, interval * 1000); }

  if (url) schedule();
})();
