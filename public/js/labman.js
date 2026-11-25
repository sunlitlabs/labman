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

  // A long action (a push to git, a sync) says what it is doing and cannot be double-submitted.
  document.addEventListener('submit', function (e) {
    var btn = e.submitter && e.submitter.matches && e.submitter.matches('button[data-busy]') ? e.submitter : null;
    if (!btn || e.defaultPrevented) return;
    var status = document.createElement('p');
    status.className = 'muted busy-note';
    status.setAttribute('role', 'status');
    status.textContent = btn.getAttribute('data-busy') + ' This can take a few seconds.';
    btn.parentNode.appendChild(status);
    btn.textContent = btn.getAttribute('data-busy');
    window.setTimeout(function () { btn.disabled = true; }, 0);
  });

  // A link that starts a slow check (pull and compare, an NPM preview) shows a spinner and the steps it is
  // working through until the next page arrives. The steps advance on a timer: the server answers with the
  // whole page at once, and the page that follows states what was actually found.
  var progressTimer = null;
  function clearProgress() {
    if (progressTimer) { window.clearInterval(progressTimer); progressTimer = null; }
    document.querySelectorAll('.progress-panel').forEach(function (p) { p.remove(); });
    document.querySelectorAll('a[data-progress].is-busy').forEach(function (a) { a.classList.remove('is-busy'); a.removeAttribute('aria-disabled'); });
  }
  document.addEventListener('click', function (e) {
    var link = e.target.closest && e.target.closest('a[data-progress]');
    if (!link) return;
    if (link.classList.contains('is-busy')) { e.preventDefault(); return; }
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    var steps;
    try { steps = JSON.parse(link.getAttribute('data-progress')); } catch (err) { steps = null; }
    if (!Array.isArray(steps) || steps.length === 0) steps = ['Working'];

    clearProgress();
    link.classList.add('is-busy');
    link.setAttribute('aria-disabled', 'true');

    var panel = document.createElement('div');
    panel.className = 'card progress-panel';
    panel.setAttribute('role', 'status');
    var head = document.createElement('p');
    head.className = 'progress-head';
    var spin = document.createElement('span');
    spin.className = 'spinner';
    spin.setAttribute('aria-hidden', 'true');
    var title = document.createElement('strong');
    title.textContent = 'Working…';
    head.appendChild(spin);
    head.appendChild(title);
    var list = document.createElement('ol');
    list.className = 'progress-steps';
    var items = steps.map(function (text) {
      var li = document.createElement('li');
      li.textContent = String(text);
      list.appendChild(li);
      return li;
    });
    panel.appendChild(head);
    panel.appendChild(list);
    var anchor = link.closest('.toolbar, .actions') || link;
    anchor.parentNode.insertBefore(panel, anchor.nextSibling);

    var at = 0;
    var show = function () {
      items.forEach(function (li, i) { li.className = i < at ? 'done' : (i === at ? 'active' : ''); });
      title.textContent = steps[at] + '…';
    };
    show();
    progressTimer = window.setInterval(function () {
      if (at < items.length - 1) { at += 1; show(); }
    }, 1200);
  });
  // Coming back with the browser's back button must not leave a stale spinner.
  window.addEventListener('pageshow', function (e) { if (e.persisted) clearProgress(); });

  // Filter the group / server pickers on the run form.
  document.querySelectorAll('[data-filter-targets]').forEach(function (input) {
    input.addEventListener('input', function () {
      var q = input.value.trim().toLowerCase();
      document.querySelectorAll('[data-target-name]').forEach(function (el) {
        el.hidden = q !== '' && el.getAttribute('data-target-name').indexOf(q) === -1;
      });
    });
  });

  // Server list: ticking servers enables "Run a workbook on the selected".
  var bulk = document.querySelector('[data-bulk-run]');
  if (bulk) {
    var refreshBulk = function () {
      var n = document.querySelectorAll('input[data-pick-server]:checked').length;
      bulk.hidden = n === 0;
      var label = bulk.querySelector('[data-bulk-count]');
      if (label) label.textContent = n + ' selected';
    };
    document.addEventListener('change', function (e) { if (e.target.matches && e.target.matches('input[data-pick-server]')) refreshBulk(); });
    refreshBulk();
  }

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

  // The dashboard's tabs (All / Services / Servers / Links) narrow what the search looks through.
  var tabs = document.querySelector('[data-tabs]');
  var currentTab = 'all';

  function applyFilter() {
    var tokens = filter ? filter.value.toLowerCase().split(/\s+/).filter(Boolean) : [];
    var visibleTotal = 0;
    var perSection = {};

    document.querySelectorAll('.group').forEach(function (group) {
      var section = group.getAttribute('data-section');
      var inTab = !section || currentTab === 'all' || section === currentTab;
      var visible = 0;
      var matching = 0;
      group.querySelectorAll('[data-filter]').forEach(function (row) {
        var hay = row.getAttribute('data-filter');
        var match = tokens.every(function (t) { return hay.indexOf(t) !== -1; });
        if (match) matching++;
        row.hidden = !(match && inTab);
        if (match && inTab) visible++;
      });
      if (section) perSection[section] = (perSection[section] || 0) + matching;
      group.hidden = visible === 0;
      visibleTotal += visible;
    });

    if (tabs) {
      var all = 0;
      Object.keys(perSection).forEach(function (k) { all += perSection[k]; });
      tabs.querySelectorAll('[data-tab-count]').forEach(function (n) {
        var k = n.getAttribute('data-tab-count');
        n.textContent = k === 'all' ? all : (perSection[k] || 0);
      });
    }

    if (noMatch) noMatch.hidden = visibleTotal !== 0 || (tokens.length === 0 && currentTab === 'all');
  }

  function selectTab(key, focus) {
    if (!tabs) return;
    var buttons = tabs.querySelectorAll('[data-tab]');
    var known = false;
    buttons.forEach(function (b) { if (b.getAttribute('data-tab') === key) known = true; });
    currentTab = known ? key : 'all';
    buttons.forEach(function (b) {
      var on = b.getAttribute('data-tab') === currentTab;
      b.setAttribute('aria-selected', on ? 'true' : 'false');
      b.setAttribute('tabindex', on ? '0' : '-1');
      if (on && focus) b.focus();
    });
    try { window.history.replaceState(null, '', currentTab === 'all' ? window.location.pathname + window.location.search : '#' + currentTab); } catch (e) { /* not essential */ }
    applyFilter();
  }

  if (tabs) {
    tabs.addEventListener('click', function (e) {
      var b = e.target.closest('[data-tab]');
      if (b) selectTab(b.getAttribute('data-tab'), false);
    });
    tabs.addEventListener('keydown', function (e) {
      if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
      var keys = Array.prototype.map.call(tabs.querySelectorAll('[data-tab]'), function (b) { return b.getAttribute('data-tab'); });
      var i = keys.indexOf(currentTab) + (e.key === 'ArrowRight' ? 1 : -1);
      selectTab(keys[(i + keys.length) % keys.length], true);
      e.preventDefault();
    });
    selectTab((window.location.hash || '').replace('#', '') || 'all', false);
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

/* Service form: the resulting URL while typing, which zone the service is in, where else it is published,
   and the zone's own domain as the suggested name. Reads the zone data the form carries; does nothing without it. */
(function () {
  'use strict';
  var form = document.querySelector('form[data-zone-info]');
  var out = document.querySelector('[data-service-preview]');
  if (!form || !out) return;

  var info;
  try { info = JSON.parse(form.getAttribute('data-zone-info')); } catch (e) { return; }

  function val(sel) { var el = form.querySelector(sel); return el ? el.value.trim() : ''; }
  function firstName(field) {
    var inputs = form.querySelectorAll('input[name="' + field + '[]"]');
    for (var i = 0; i < inputs.length; i++) if (inputs[i].value.trim() !== '') return inputs[i].value.trim();
    return '';
  }

  function zoneId() {
    var own = val('#zone_id');
    if (own) return own;
    var server = info.servers[val('#server_id')];
    return server && server.zone ? String(server.zone) : String(info.default);
  }

  function refresh() {
    var zone = info.zones[zoneId()];
    var server = info.servers[val('#server_id')];
    var host = firstName('local_domains') || firstName('public_domains') || (server ? server.address : val('#address'));
    var scheme = val('#scheme') || 'http';
    var port = val('#port');
    var defaultPort = scheme === 'https' ? '443' : '80';
    var path = val('#path') || '/';

    var text = host ? 'Resulting address: ' + scheme + '://' + host + (port && port !== defaultPort ? ':' + port : '') + path : 'Resulting address: enter a name, a server or an address.';
    var lines = [text];

    if (zone) {
      var also = (zone.mirrors_to || []).slice();
      form.querySelectorAll('input[name="publish_in[]"]:checked').forEach(function (box) {
        var z = info.zones[box.value];
        if (z && also.indexOf(z.name) === -1) also.push(z.name);
      });
      lines.push('Zone: ' + zone.name + (info.multiple ? '.' : ''));
      if (info.multiple) lines[lines.length - 1] += also.length ? ' Also published in: ' + also.join(', ') + '.' : ' Not published in other zones.';
    }
    out.textContent = lines.join(' ');

    // Suggest names under the zone's own domains when "Add" is pressed, and say what "Automatic" means for its certificate.
    form.querySelectorAll('[data-listedit]').forEach(function (box) {
      var tpl = box.querySelector('template[data-row]');
      var probe = box.querySelector('.listedit-rows input[type=text]') || (tpl && tpl.content ? tpl.content.querySelector('input[type=text]') : null);
      var name = probe ? probe.name : '';
      if (!box.hasAttribute('data-suffix-default')) box.setAttribute('data-suffix-default', box.getAttribute('data-suffix') || '');
      var wanted = zone ? (name.indexOf('public_domains') === 0 ? zone['public'] : zone.local) : null;
      var suffix = wanted || box.getAttribute('data-suffix-default');
      if (suffix) box.setAttribute('data-suffix', suffix);
    });
    var auto = form.querySelector('#cert_mode option[value="auto"]');
    if (auto && zone && zone.cert) auto.textContent = 'Automatic (' + zone.name + ': ' + zone.cert + ')';
  }

  form.addEventListener('input', refresh);
  form.addEventListener('change', refresh);
  refresh();
})();