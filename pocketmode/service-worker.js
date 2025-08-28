/* /pocketmode/service-worker.js
   Scope: /pocketmode/
   - 基本は素通し（fetchハンドラ無しのため）
   - ただし /images/ball*.png だけは軽くキャッシュ（任意）
*/
const VERSION = 'pm-sw-v1';

self.addEventListener('install', (event) => {
  // すぐ有効化
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  // 古いキャッシュを掃除（今回は balls-* のみ）
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(
      keys
        .filter(k => k.startsWith('balls-') && !k.endsWith(VERSION))
        .map(k => caches.delete(k))
    );
  })());
  self.clients.claim();
});

// 画像だけ簡易キャッシュ（無くてもOK）
self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);
  if (url.pathname.startsWith('/images/ball')) {
    event.respondWith((async () => {
      const cache = await caches.open('balls-' + VERSION);
      const cached = await cache.match(event.request);
      if (cached) {
        // バックグラウンドで更新（失敗しても無視）
        fetch(event.request).then(r => cache.put(event.request, r.clone())).catch(() => {});
        return cached;
      }
      const resp = await fetch(event.request);
      cache.put(event.request, resp.clone());
      return resp;
    })());
  }
  // それ以外は Service Worker で何もしない（＝ブラウザに任せる）
});
