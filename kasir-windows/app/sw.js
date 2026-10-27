const C = 'kasir-v3';
const PRE = ['offline.html', 'assets/style.css', 'assets/tablet.css', 'assets/kasir.css', 'assets/kasir.js', 'assets/ean-scan.js', 'assets/icon-192.png', 'assets/icon-512.png'];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(C).then(c => c.addAll(PRE)));
  self.skipWaiting();
});

self.addEventListener('activate', e => {
  e.waitUntil(caches.keys().then(ks => Promise.all(ks.filter(k => k !== C).map(k => caches.delete(k)))));
  self.clients.claim();
});

/* Halaman kasir selalu diambil dari server (data harus terbaru).
   Cache hanya dipakai untuk tampilan "tidak ada koneksi". */
self.addEventListener('fetch', e => {
  if (e.request.method !== 'GET') return;
  if (e.request.mode === 'navigate') {
    e.respondWith(fetch(e.request).catch(() => caches.match('offline.html')));
    return;
  }
  e.respondWith(fetch(e.request).catch(() => caches.match(e.request)));
});
