// PlastiLink shared behaviour (no framework).
(function () {
  'use strict';

  // Mobile sidebar toggle
  var toggle = document.querySelector('[data-sidebar-toggle]');
  var sidebar = document.querySelector('.sidebar');
  var backdrop = document.querySelector('.sidebar-backdrop');
  function setOpen(open) {
    if (!sidebar) return;
    sidebar.classList.toggle('open', open);
    if (backdrop) backdrop.classList.toggle('open', open);
    if (toggle) toggle.setAttribute('aria-expanded', String(open));
  }
  if (toggle) toggle.addEventListener('click', function () { setOpen(!sidebar.classList.contains('open')); });
  if (backdrop) backdrop.addEventListener('click', function () { setOpen(false); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setOpen(false); });

  // Show/hide password
  document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var input = document.querySelector(btn.getAttribute('data-toggle-password'));
      if (!input) return;
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
      btn.firstElementChild.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
    });
  });

  // Record-a-collection form: picker search + live net weight / value preview.
  // (Convenience only. The server recalculates everything and is the source of truth.)
  var cform = document.querySelector('[data-collection-form]');
  if (cform) {
    var search = cform.querySelector('#picker_search');
    var results = cform.querySelector('#picker_results');
    var pickerId = cform.querySelector('[name=picker_id]');
    var pickerLabel = cform.querySelector('[name=picker_label]');
    var chosen = cform.querySelector('#picker_chosen');
    var timer = null;

    var hideResults = function () { results.innerHTML = ''; results.hidden = true; };
    var choose = function (p) {
      pickerId.value = p.id;
      pickerLabel.value = p.name + ' (' + p.code + ')';
      chosen.textContent = 'Selected: ' + p.name + ' (' + p.code + ') · ' + p.neighbourhood;
      chosen.hidden = false;
      search.value = '';
      hideResults();
    };
    search.addEventListener('input', function () {
      clearTimeout(timer);
      pickerId.value = '';
      chosen.hidden = true;
      var q = search.value.trim();
      if (q.length < 2) { hideResults(); return; }
      timer = setTimeout(function () {
        fetch(cform.dataset.searchUrl + '?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            results.innerHTML = '';
            if (!d.results.length) {
              var none = document.createElement('div');
              none.className = 'list-group-item text-muted';
              none.textContent = 'No active picker found.';
              results.appendChild(none);
            }
            d.results.forEach(function (p) {
              var b = document.createElement('button');
              b.type = 'button';
              b.className = 'list-group-item list-group-item-action';
              b.textContent = p.name + ' · ' + p.code + ' · ' + p.neighbourhood + ' · ' + p.phone;
              b.addEventListener('click', function () { choose(p); });
              results.appendChild(b);
            });
            results.hidden = false;
          })
          .catch(function () { /* the picker ID box below still works */ });
      }, 250);
    });

    var prices = {};
    try { prices = JSON.parse(cform.dataset.prices || '{}'); } catch (e) { prices = {}; }
    var calc = cform.querySelector('#calc');
    var parseKg = function (v) {
      var m = /^(\d{1,5})(?:\.(\d{1,2}))?$/.exec((v || '').trim().replace(',', '.'));
      return m ? parseInt(m[1], 10) * 100 + parseInt(((m[2] || '') + '00').slice(0, 2), 10) : null;
    };
    var update = function () {
      var hub = cform.querySelector('[name=hub_id]').value;
      var cat = cform.querySelector('[name=plastic_category_id]').value;
      var g = parseKg(cform.querySelector('[name=gross_weight_kg]').value);
      var tRaw = cform.querySelector('[name=tare_weight_kg]').value.trim();
      var t = tRaw === '' ? 0 : parseKg(tRaw);
      var price = (prices[hub] || {})[cat];
      if (g === null || t === null || g <= t) { calc.textContent = 'Enter the weights to see the net weight and value.'; return; }
      var net = g - t;
      var text = 'Net weight: ' + (net / 100).toFixed(2).replace(/\.?0+$/, '') + ' kg';
      if (price) { text += ' · Value: UGX ' + Math.floor((net * price + 50) / 100).toLocaleString('en-US') + ' (at UGX ' + price.toLocaleString('en-US') + '/kg)'; }
      else { text += ' · No price is set for this hub and material.'; }
      calc.textContent = text;
    };
    cform.addEventListener('input', update);
    cform.addEventListener('change', update);
    update();
  }

  // Loading state on forms: prevents double submission
  document.querySelectorAll('form[data-loading]').forEach(function (form) {
    form.addEventListener('submit', function () {
      var btn = form.querySelector('[type="submit"]');
      if (btn) {
        btn.disabled = true;
        btn.insertAdjacentHTML('afterbegin', '<span class="spinner-pl" aria-hidden="true"></span>');
      }
    });
  });
})();
