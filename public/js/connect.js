/* Inventory connection form: understands a pasted link (GitHub, GitLab, Gitea, ...) and fills in the rest. CSP-safe. */
(function () {
  'use strict';

  var form = document.getElementById('connect-form');
  if (!form) return;

  var link = document.getElementById('repo_url');
  var branch = document.getElementById('branch');
  var path = document.getElementById('path');
  var detected = document.getElementById('detected');
  var help = document.getElementById('token-help');
  var details = form.querySelector('details');
  var providers = { github: 'GitHub', gitlab: 'GitLab', gitea: 'Gitea / Forgejo', bitbucket: 'Bitbucket', other: 'Git' };
  var timer = null;
  var seq = 0;

  function show(data) {
    var parts = [providers[data.provider] || 'Git', data.repo];
    if (data.branch) parts.push('branch ' + data.branch);
    if (data.path) parts.push(data.path);
    detected.textContent = '✓ Understood: ' + parts.join(' · ');
    detected.hidden = false;

    if (data.branch) branch.value = data.branch;
    if (data.path) path.value = data.path;
    if (!data.path && !path.value && details) details.open = true;

    if (data.token_url) {
      help.href = data.token_url;
      help.textContent = 'Create a token on ' + (providers[data.provider] || 'your git host') + ' →';
      help.hidden = false;
    } else {
      help.hidden = true;
    }
  }

  function hide() {
    detected.hidden = true;
    help.hidden = true;
  }

  function parse() {
    var value = link.value.trim();
    if (value.length < 8) { hide(); return; }
    var mine = ++seq;

    fetch(form.getAttribute('data-parse-url') + '?url=' + encodeURIComponent(value), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : { ok: false }; })
      .then(function (data) {
        if (mine !== seq) return; // a newer paste has already replaced this answer
        if (data.ok) show(data); else hide();
      })
      .catch(hide);
  }

  link.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(parse, 250); });
  link.addEventListener('paste', function () { setTimeout(parse, 30); });
  if (link.value.trim() !== '') parse();
})();
