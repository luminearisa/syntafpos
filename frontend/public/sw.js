const CACHE_PREFIX = 'syntafpos-shell-';
const CACHE_NAME = `${CACHE_PREFIX}v2`;

function scopedUrl(path) {
  return new URL(path, self.registration.scope).toString();
}

async function cacheRequest(cache, url) {
  try {
    const response = await fetch(url, { cache: 'reload' });
    if (response.ok) await cache.put(url, response);
  } catch {
    // Optional shell assets should not block installation.
  }
}

self.addEventListener('install', (event) => {
  event.waitUntil((async () => {
    const cache = await caches.open(CACHE_NAME);
    const coreIcons = [
      'calculator-outline',
      'business-outline',
      'storefront-outline',
      'cube-outline',
      'cash-outline',
      'receipt-outline',
      'home-outline',
      'grid-outline',
      'menu-outline',
      'close-outline',
      'people-outline',
      'download-outline',
      'person-outline',
      'chevron-down-outline',
      'lock-closed-outline',
      'mail-outline',
      'eye-outline',
      'eye-off-outline',
      'arrow-forward-outline',
      'shield-checkmark-outline',
      'analytics-outline',
      'globe-outline',
      'sync-outline',
      'alert-circle-outline',
    ];

    const shell = [
      scopedUrl(''),
      scopedUrl('manifest.webmanifest'),
      scopedUrl('favicon.svg'),
      scopedUrl('icons/icon-192.png'),
      scopedUrl('icons/icon-512.png'),
      scopedUrl('icons/icon-maskable-512.png'),
      scopedUrl('icons/apple-touch-icon.png'),
      ...coreIcons.map((name) => scopedUrl(`ionicons/svg/${name}.svg`)),
    ];
    await Promise.all(shell.map((url) => cacheRequest(cache, url)));

    // Vite's HTML names the hashed entry point and stylesheet; pre-cache these
    // so the initial application shell can reopen without a network connection.
    const indexUrl = scopedUrl('index.html');
    try {
      const response = await fetch(indexUrl, { cache: 'reload' });
      if (response.ok) {
        await cache.put(indexUrl, response.clone());
        const html = await response.text();
        const assets = [...html.matchAll(/(?:src|href)=["']([^"']+\.(?:js|css))["']/gi)]
          .map((match) => match[1])
          .filter((asset) => typeof asset === 'string')
          .map((asset) => new URL(asset, self.registration.scope).toString());
        await Promise.all(assets.map((url) => cacheRequest(cache, url)));
      }
    } catch {
      // Runtime caching will keep newly fetched chunks available on later visits.
    }

    await self.skipWaiting();
  })());
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(keys
      .filter((key) => key.startsWith(CACHE_PREFIX) && key !== CACHE_NAME)
      .map((key) => caches.delete(key)));
    await self.clients.claim();
  })());
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  const url = new URL(request.url);

  if (request.method !== 'GET' || url.origin !== self.location.origin) return;
  // Never store API responses, credentials or transaction data in the shell cache.
  if (/\/api(?:\/|$)|\/sanctum(?:\/|$)/i.test(url.pathname)) return;

  if (request.mode === 'navigate') {
    event.respondWith((async () => {
      const cache = await caches.open(CACHE_NAME);
      try {
        const response = await fetch(request);
        if (response.ok) {
          await cache.put(request, response.clone());
          await cache.put(scopedUrl('index.html'), response.clone());
        }
        return response;
      } catch {
        return (await cache.match(request)) ||
          (await cache.match(scopedUrl('index.html'))) ||
          Response.error();
      }
    })());
    return;
  }

  const isStaticAsset = /\/(?:assets|icons|ionicons\/svg)\/|\.(?:css|js|svg|png|webmanifest)$/i.test(url.pathname);
  if (!isStaticAsset) return;

  event.respondWith((async () => {
    const cache = await caches.open(CACHE_NAME);
    const cached = await cache.match(request);
    if (cached) return cached;

    try {
      const response = await fetch(request);
      if (response.ok && response.type === 'basic') {
        await cache.put(request, response.clone());
      }
      return response;
    } catch {
      return (await cache.match(request)) || Response.error();
    }
  })());
});
