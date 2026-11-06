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

  // Report charts: Chart.js (about 200 KB) is downloaded only when someone asks to see the chart.
  var chartBtn = document.querySelector('[data-chart-toggle]');
  if (chartBtn) {
    var panel = document.getElementById('chart-panel');
    var palette = ['#086B52', '#16A085', '#F0B44D', '#202C28'];
    var draw = function () {
      var cfg = JSON.parse(panel.dataset.chart);
      new window.Chart(panel.querySelector('canvas'), {
        type: cfg.type,
        data: {
          labels: cfg.labels,
          datasets: cfg.datasets.map(function (d, i) {
            return Object.assign({ backgroundColor: palette[i % 4], borderColor: palette[i % 4], borderWidth: cfg.type === 'line' ? 2 : 0, tension: 0.25 }, d);
          })
        },
        options: { responsive: true, plugins: { title: { display: true, text: cfg.title }, legend: { display: cfg.datasets.length > 1 } }, scales: { y: { beginAtZero: true } } }
      });
      panel.dataset.drawn = '1';
    };
    chartBtn.addEventListener('click', function () {
      if (!panel.hidden) { panel.hidden = true; chartBtn.lastChild.textContent = ' Show chart'; return; }
      panel.hidden = false;
      chartBtn.lastChild.textContent = ' Hide chart';
      if (panel.dataset.drawn) return;
      if (window.Chart) { draw(); return; }
      var s = document.createElement('script');
      s.src = chartBtn.dataset.src;
      s.onload = draw;
      document.head.appendChild(s);
    });
  }

  // Maps: Leaflet (about 150 KB) and the street tiles are only fetched when someone asks for a map.
  var leafletLoading = null;
  function withLeaflet(src, css, then) {
    if (window.L) { then(); return; }
    if (!leafletLoading) {
      leafletLoading = [];
      var link = document.createElement('link');
      link.rel = 'stylesheet';
      link.href = css;
      document.head.appendChild(link);
      var s = document.createElement('script');
      s.src = src;
      s.onload = function () { leafletLoading.splice(0).forEach(function (f) { f(); }); };
      document.head.appendChild(s);
    }
    leafletLoading.push(then);
  }
  function baseLayer(el) {
    return window.L.tileLayer(el.dataset.tile, { attribution: el.dataset.attribution || '', maxZoom: 19 });
  }
  function popupNode(lines) {          // built from text nodes, never from HTML strings
    var d = document.createElement('div');
    lines.forEach(function (t, i) {
      if (i) d.appendChild(document.createElement('br'));
      var n = i === 0 ? document.createElement('strong') : document.createElement('span');
      n.textContent = t;
      d.appendChild(n);
    });
    return d;
  }
  function kg(n) { return (Math.round(n * 100) / 100).toLocaleString('en-US') + ' kg'; }

  var mapBtn = document.querySelector('[data-map-toggle]');
  if (mapBtn) {
    var box = document.getElementById('monitor-map');
    var label = mapBtn.querySelector('span');
    var built = null;
    var buildMap = function () {
      var pts = JSON.parse(box.dataset.points);
      var center = JSON.parse(box.dataset.center);
      var map = window.L.map(box.querySelector('.monitor-map-canvas'));
      baseLayer(box).addTo(map);
      var maxKg = pts.hotspots.reduce(function (m, p) { return Math.max(m, p.kg); }, 0) || 1;
      var groups = {
        hotspots: window.L.layerGroup(pts.hotspots.map(function (p) {
          return window.L.circleMarker([p.lat, p.lng], { radius: 10 + 30 * Math.sqrt(p.kg / maxKg), color: '#b3261e', fillColor: '#e8590c', fillOpacity: 0.45, weight: 2 })
            .bindPopup(popupNode([p.name, kg(p.kg) + ' recovered', p.collections + ' collections · ' + p.pickers + ' pickers']));
        })),
        hubs: window.L.layerGroup(pts.hubs.map(function (p) {
          return window.L.marker([p.lat, p.lng], { title: p.name }).bindPopup(popupNode([p.name, 'Hub (' + p.status + ')', kg(p.kg) + ' collected', kg(p.stock) + ' in stock']));
        })),
        recyclers: window.L.layerGroup(pts.recyclers.map(function (p) {
          return window.L.circleMarker([p.lat, p.lng], { radius: 8, color: '#0b3d91', fillColor: '#2f6fdb', fillOpacity: 0.9, weight: 2 }).bindPopup(popupNode([p.name, 'Recycling company']));
        }))
      };
      var all = [];
      Object.keys(groups).forEach(function (k) { groups[k].addTo(map); pts[k].forEach(function (p) { all.push([p.lat, p.lng]); }); });
      if (all.length) { map.fitBounds(all, { padding: [40, 40], maxZoom: 15 }); } else { map.setView(center, 12); }
      document.querySelectorAll('[data-layer]').forEach(function (cb) {
        cb.addEventListener('change', function () {
          var g = groups[cb.dataset.layer];
          if (g) { cb.checked ? g.addTo(map) : map.removeLayer(g); }
        });
      });
      return map;
    };
    mapBtn.addEventListener('click', function () {
      if (!box.hidden) { box.hidden = true; label.textContent = 'Show map'; return; }
      box.hidden = false;
      label.textContent = 'Hide map';
      withLeaflet(mapBtn.dataset.src, mapBtn.dataset.css, function () {
        if (!built) { built = buildMap(); }
        built.invalidateSize();
      });
    });
  }

  // Click-to-place: fills latitude/longitude inputs from a map click (hub form, neighbourhoods, recyclers)
  var pickers = {};
  document.querySelectorAll('[data-geo-pick]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var latIn = document.querySelector(btn.dataset.lat);
      var lngIn = document.querySelector(btn.dataset.lng);
      var host = document.querySelector(btn.dataset.map);
      if (!latIn || !lngIn || !host) return;
      host.hidden = false;
      withLeaflet(btn.dataset.src, btn.dataset.css, function () {
        var st = pickers[btn.dataset.map];
        if (!st) {
          var center = JSON.parse(host.dataset.center);
          st = pickers[btn.dataset.map] = { map: window.L.map(host.querySelector('.geo-picker-map')).setView(center, 12), marker: null, lat: null, lng: null };
          baseLayer(host).addTo(st.map);
          st.map.on('click', function (e) {
            st.lat.value = e.latlng.lat.toFixed(6);
            st.lng.value = e.latlng.lng.toFixed(6);
            if (st.marker) { st.marker.setLatLng(e.latlng); } else { st.marker = window.L.marker(e.latlng).addTo(st.map); }
          });
        }
        st.lat = latIn;
        st.lng = lngIn;
        st.map.invalidateSize();
        var la = parseFloat(latIn.value), lo = parseFloat(lngIn.value);
        if (isFinite(la) && isFinite(lo)) {
          if (st.marker) { st.marker.setLatLng([la, lo]); } else { st.marker = window.L.marker([la, lo]).addTo(st.map); }
          st.map.setView([la, lo], 15);
        } else if (st.marker) { st.map.removeLayer(st.marker); st.marker = null; }
      });
    });
  });

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
