/* Follows a Semaphore run: polls LabMan for the status and new output lines. CSP-safe, no dependencies. */
(function () {
  'use strict';

  var body = document.body;
  var url = body.getAttribute('data-task-url');
  if (!url) return;

  var total = parseInt(body.getAttribute('data-task-total'), 10) || 0;
  var finished = body.getAttribute('data-task-finished') === '1';
  var out = document.getElementById('task-output');
  var statusEl = document.getElementById('task-status');
  var live = document.getElementById('task-live');
  var empty = document.getElementById('task-empty');
  var stopForm = document.getElementById('stop-form');

  var classes = { success: 'up', error: 'down', stopped: 'degraded' };

  function setStatus(status) {
    statusEl.textContent = status;
    statusEl.className = 'pill pill-' + (classes[status] || 'unknown');
  }

  function tick() {
    if (finished) return;

    fetch(url + '?since=' + total, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) {
        if (r.status === 401 || r.status === 403) { window.location.reload(); throw new Error('auth'); }
        return r.json();
      })
      .then(function (data) {
        if (data.error) { if (live) live.textContent = data.error; return; }

        if (data.lines && data.lines.length) {
          var atBottom = window.innerHeight + window.scrollY >= document.body.scrollHeight - 80;
          out.appendChild(document.createTextNode(data.lines.join('\n') + '\n'));
          total = data.total;
          if (empty) { empty.hidden = true; }
          if (atBottom) window.scrollTo(0, document.body.scrollHeight);
        }

        setStatus(data.status);

        if (data.finished) {
          finished = true;
          if (live) live.hidden = true;
          if (stopForm) stopForm.hidden = true;
        }
      })
      .catch(function (err) {
        if (err && err.message === 'auth') return;
        if (live) live.textContent = 'Lost contact with LabMan, retrying…';
      })
      .then(function () { if (!finished) setTimeout(tick, 2000); });
  }

  setTimeout(tick, 1500);
})();
