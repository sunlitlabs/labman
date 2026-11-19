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
    var row = e.target.closest('.row.clickable');
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
    var row = e.target.closest && e.target.closest('.row[data-ssh]');
    if (row && e.target === row && (e.key === 'Enter' || e.key === ' ')) {
      e.preventDefault();
      copyRow(row);
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
      group.querySelectorAll('.row[data-filter]').forEach(function (row) {
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

  function renderChecks(container, checks) {
    container.textContent = '';
    if (!checks || !checks.length) {
      container.appendChild(el('span', 'chk', 'Waiting for the first check…'));
      return;
    }
    checks.forEach(function (c) {
      var parts = [c.label, c.detail];
      if (c.ms !== null && c.ms !== undefined) parts.push(c.ms + ' ms');
      container.appendChild(el('span', 'chk' + (c.ok ? '' : ' bad'), parts.join(' · ')));
    });
  }

  function applyItem(kind, item) {
    var row = document.querySelector('.row[data-kind="' + kind + '"][data-id="' + item.id + '"]');
    if (!row) return;
    var pill = row.querySelector('[data-pill]');
    if (pill) {
      pill.className = 'pill pill-' + item.state + (item.stale ? ' is-stale' : '');
      pill.textContent = item.stale ? item.state + ' (stale)' : item.state;
      pill.title = item.checked_at ? 'Checked ' + new Date(item.checked_at).toLocaleString() : 'Not checked yet';
    }
    var detail = row.querySelector('[data-detail]');
    if (detail) renderChecks(detail, item.checks);
  }

  function applySummary(kind, counts) {
    ['up', 'degraded', 'down', 'unknown'].forEach(function (state) {
      var n = document.querySelector('[data-count="' + kind + ':' + state + '"]');
      if (n) n.textContent = counts[state];
    });
  }

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
