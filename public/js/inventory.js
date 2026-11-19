/* Inventory compare page: bulk choices and a live count of selected changes. No dependencies, CSP-safe. */
(function () {
  'use strict';

  var form = document.getElementById('choices-form');
  var counter = document.getElementById('selected-count');
  if (!form) return;

  function selected() {
    var n = 0;
    form.querySelectorAll('input[type=radio]:checked').forEach(function (r) {
      if (r.value !== 'leave' && r.value !== 'skip') n++;
    });
    return n;
  }

  function refresh() {
    if (!counter) return;
    var n = selected();
    counter.textContent = n === 0 ? 'No changes selected' : n + ' change' + (n === 1 ? '' : 's') + ' selected';
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('button[data-bulk]');
    if (!btn) return;

    var group = btn.getAttribute('data-bulk');
    var value = btn.getAttribute('data-value');
    form.querySelectorAll('input[type=radio][data-group="' + group + '"]').forEach(function (r) {
      if (r.value === value) r.checked = true;
    });
    refresh();
  });

  form.addEventListener('change', refresh);
  refresh();
})();
