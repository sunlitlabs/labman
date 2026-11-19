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

  // ------------------------------------------------------ add / remove lists
  function slugify(text) {
    return text.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
  }

  document.addEventListener('click', function (e) {
    var add = e.target.closest && e.target.closest('[data-listedit] [data-add]');
    if (add) {
      var box = add.closest('[data-listedit]');
      var tpl = box.querySelector('template[data-row]');
      var rows = box.querySelector('.listedit-rows');
      var node = tpl.content.firstElementChild.cloneNode(true);
      var input = node.querySelector('input[type=text]');
      var suffix = box.getAttribute('data-suffix');
      var from = box.getAttribute('data-slug-from') && document.getElementById(box.getAttribute('data-slug-from'));
      if (suffix && from && slugify(from.value)) input.value = slugify(from.value) + '.' + suffix;
      rows.appendChild(node);
      var radio = node.querySelector('input[type=radio]');
      if (radio) {
        radio.value = input.value;
        if (rows.querySelectorAll('input[type=radio]').length === 1) radio.checked = true;
      }
      input.focus();
      input.select();
      return;
    }
    var rm = e.target.closest && e.target.closest('[data-listedit] [data-remove]');
    if (rm) {
      var row = rm.closest('.listedit-row');
      var list = row.parentNode;
      var wasDefault = row.querySelector('input[type=radio]:checked');
      list.removeChild(row);
      if (wasDefault) {
        var first = list.querySelector('input[type=radio]');
        if (first) first.checked = true;
      }
    }
  });

  // The default radio carries the row's text, so it follows edits.
  document.addEventListener('input', function (e) {
    var input = e.target;
    if (input.matches && input.matches('[data-listedit] .listedit-row input[type=text]')) {
      var radio = input.closest('.listedit-row').querySelector('input[type=radio]');
      if (radio) radio.value = input.value.trim();
    }
  });

  // ----------------------------------------------------------- icon picker
  document.querySelectorAll('[data-iconpick]').forEach(function (box) {
    var hidden = box.querySelector('input[type=hidden]');
    var toggle = box.querySelector('[data-toggle]');
    var panel = box.querySelector('.iconpick-panel');
    var search = box.querySelector('[data-search]');
    var list = box.querySelector('[data-list]');
    var note = box.querySelector('[data-note]');
    var fixed = Array.prototype.slice.call(list.children);
    var timer = null;
    var seq = 0;

    function setThumb(slug) {
      var holder = toggle.querySelector('.ico');
      holder.textContent = '';
      if (!slug) return;
      var img = document.createElement('img');
      img.className = 'ico-img';
      img.alt = '';
      img.src = box.getAttribute('data-icon-url') + '/' + encodeURIComponent(slug);
      img.addEventListener('error', function () { img.hidden = true; });
      holder.appendChild(img);
    }

    function choose(value) {
      hidden.value = value;
      var auto = box.getAttribute('data-auto');
      toggle.querySelector('[data-label]').textContent = value === 'none' ? 'No icon' : value !== '' ? value : 'Automatic' + (auto ? ' (' + auto + ')' : '');
      setThumb(value === 'none' ? null : value !== '' ? value : auto);
      close(true);
    }

    function open() {
      panel.hidden = false;
      toggle.setAttribute('aria-expanded', 'true');
      if (!search.value) {
        var src = document.getElementById(box.getAttribute('data-seed-from'));
        search.value = src ? src.value.trim() : '';
      }
      run();
      search.focus();
      search.select();
    }

    function close(refocus) {
      panel.hidden = true;
      toggle.setAttribute('aria-expanded', 'false');
      if (refocus) toggle.focus();
    }

    function render(slugs) {
      Array.prototype.slice.call(list.children).forEach(function (li) { if (fixed.indexOf(li) === -1) list.removeChild(li); });
      slugs.forEach(function (slug) {
        var li = document.createElement('li');
        var b = document.createElement('button');
        b.type = 'button';
        b.setAttribute('role', 'option');
        b.setAttribute('data-value', slug);
        var img = document.createElement('img');
        img.className = 'pick-img';
        img.alt = '';
        img.loading = 'lazy';
        img.src = box.getAttribute('data-icon-url') + '/' + encodeURIComponent(slug);
        img.addEventListener('error', function () { img.style.visibility = 'hidden'; });
        b.appendChild(img);
        b.appendChild(document.createTextNode(slug));
        li.appendChild(b);
        list.appendChild(li);
      });
      note.textContent = slugs.length ? '' : (search.value.trim() ? 'No icon in the library matches that.' : 'Type to search the library.');
    }

    function run() {
      var q = search.value.trim();
      var mine = ++seq;
      if (q.length < 2) { render([]); return; }
      fetch(box.getAttribute('data-search-url') + '?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : { icons: [] }; })
        .then(function (data) { if (mine === seq) render(data.icons || []); })
        .catch(function () { if (mine === seq) render([]); });
    }

    toggle.addEventListener('click', function () { panel.hidden ? open() : close(false); });
    search.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(run, 180); });
    list.addEventListener('click', function (e) {
      var b = e.target.closest('button[data-value]');
      if (b) choose(b.getAttribute('data-value'));
    });
    box.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !panel.hidden) { e.preventDefault(); close(true); return; }
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        var items = Array.prototype.slice.call(panel.querySelectorAll('[data-search], button[data-value]'));
        var at = items.indexOf(document.activeElement);
        if (at === -1) return;
        e.preventDefault();
        var next = items[Math.max(0, Math.min(items.length - 1, at + (e.key === 'ArrowDown' ? 1 : -1)))];
        next.focus();
      }
      if (e.key === 'Enter' && document.activeElement === search) {
        e.preventDefault();
        var first = list.querySelector('li:nth-child(n+3) button');
        if (first) choose(first.getAttribute('data-value'));
      }
    });
    document.addEventListener('click', function (e) { if (!panel.hidden && !box.contains(e.target)) close(false); });
  });

  // A checkbox that reloads the page with its setting (the wizard's suggestion switch).
  document.querySelectorAll('[data-autosubmit]').forEach(function (box) {
    box.addEventListener('change', function () { box.form.submit(); });
  });

  // Ctrl/Cmd+S saves the form on the page.
  document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
      var btn = document.querySelector('.savebar button.primary[type=submit]:not(:disabled)');
      if (btn) { e.preventDefault(); btn.click(); }
    }
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
