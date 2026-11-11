// PlastiLink service worker.
// It only speeds up static files (CSS, JS, images, icons) and shows a friendly page when the phone is offline.
// It NEVER stores pages or API responses: signed-in pages hold personal and financial data and always come from the server.
'use strict';

var VERSION = 'pl-static-v2';
var BASE = new URL('./', self.location).pathname;      // e.g. /PlastiLink/ or /
var OFFLINE = BASE + 'offline.html';

self.addEventListener('install', function (event) {
  event.waitUntil(caches.open(VERSION).then(function (c) { return c.addAll([OFFLINE, BASE + 'assets/img/brand/logo-reversed.png', BASE + 'assets/img/icons/icon-192.png']); }).then(function () { return self.skipWaiting(); }));
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(keys.filter(function (k) { return k !== VERSION; }).map(function (k) { return caches.delete(k); }));
    }).then(function () { return self.clients.claim(); })
  );
});

self.addEventListener('fetch', function (event) {
  var req = event.request;
  if (req.method !== 'GET') { return; }
  var url = new URL(req.url);
  if (url.origin !== self.location.origin) { return; }

  // Pages: always the network; if it is unreachable, show the offline page.
  if (req.mode === 'navigate') {
    event.respondWith(fetch(req).catch(function () { return caches.match(OFFLINE); }));
    return;
  }

  // Versioned static files: cache first, refresh in the background.
  if (url.pathname.indexOf(BASE + 'assets/') === 0) {
    event.respondWith(
      caches.open(VERSION).then(function (cache) {
        return cache.match(req).then(function (hit) {
          var net = fetch(req).then(function (res) {
            if (res && res.status === 200) { cache.put(req, res.clone()); }
            return res;
          }).catch(function () { return hit; });
          return hit || net;
        });
      })
    );
  }
});
