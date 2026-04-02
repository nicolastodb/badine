const CACHE = 'badine-v10';
const ASSETS = [
  './',
  './index.html',
  './viewer.html',
  './logobadine.jpg',
  './icon-192.png',
  './icon-512.png',
  './apple-touch-icon.png',
  './manifest.json',
  'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js'
];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(ASSETS)).catch(() => {}));
  self.skipWaiting();
});

self.addEventListener('activate', e => {
  e.waitUntil(caches.keys().then(keys =>
    Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))
  ));
  self.clients.claim();
});

self.addEventListener('fetch', e => {
  // Don't cache cmd.php (Watch polling must stay live)
  if (e.request.url.includes('cmd.php')) return;
  e.respondWith(
    caches.match(e.request).then(cached => cached || fetch(e.request).catch(() => cached))
  );
});
