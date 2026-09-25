/* ============================================================
   Service Worker：让站点可以「装到桌面」并且离线能看

   注册方是 public/assets/js/pwa.js；这个文件的地址必须是站点根路径
   （/sw.js），因为 SW 的可控范围由它自己的 URL 决定——放在子目录里就只能管那个子目录。

   缓存策略只有一种：**先问网络，网络不通才翻缓存**。
   没有用「缓存优先」是有意的：这个站的 CSS / JS 都是没有指纹的固定文件名
   （/assets/css/style.css），缓存优先会让改过样式的用户一直看到旧页面，
   而且看不出为什么。这里的量级（五个页面 + 几个脚本）走网络几乎不花时间，
   用一点点速度换一个「刷新看到的一定是最新的」，值。

   三条不碰缓存的规矩：
     · /api/ 下的任何请求——接口返回里带用户内容与登录态，缓存下来既陈旧又危险
     · 跨域请求——本站没有第三方资源（CSP 就是 script-src 'self'），一律放行不接管
     · 非 GET——写操作不该被拦截重放
   ============================================================ */

const CACHE_NAME = 'web-one-v4';/* 加了 /software 这一页与新版 core.js / software.js：换版本名把旧缓存整份丢掉 */
const OFFLINE_URL = '/offline.html';
// 装好之后先把这两样存起来：离线页是兜底，清单在离线时也要能被读到
const PRECACHE = [OFFLINE_URL, '/manifest.webmanifest'];

self.addEventListener('install', function (event) {
  event.waitUntil(
    caches.open(CACHE_NAME).then(function (cache) {
      // 单个文件取不到就整体失败：宁可这次不装，也不留一个「离线页本身是 404」的缓存
      return Promise.all(PRECACHE.map(function (url) { return cache.add(url); }));
    }).then(function () { return self.skipWaiting(); })
  );
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys().then(function (keys) {
      // 换版本时把老缓存清掉，不然每次改 sw.js 都会多留一份全量副本
      return Promise.all(keys.filter(function (key) {
        return key !== CACHE_NAME;
      }).map(function (key) { return caches.delete(key); }));
    }).then(function () { return self.clients.claim(); })
  );
});

self.addEventListener('fetch', function (event) {
  const request = event.request;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;// 第三方：原样放行，不接管
  if (url.pathname.indexOf('/api/') === 0) return;// 接口：永远走真实网络

  event.respondWith(networkFirst(request, url));
});

/**
 * 先网络、后缓存。
 * @param {Request} request 原始请求
 * @param {URL} url 已经解析过的地址（省得再算一次）
 */
function networkFirst(request, url) {
  return fetch(request).then(function (response) {
    if (shouldCache(request, url, response)) {
      // clone：Response 的 body 是一次性的，给浏览器的和存缓存的得是两份
      const copy = response.clone();
      caches.open(CACHE_NAME).then(function (cache) {
        cache.put(url.pathname, copy);
      });
    }
    return response;
  }).catch(function () {
    return caches.match(request).then(function (cached) {
      if (cached) return cached;
      if (request.mode === 'navigate') {
        // 只有「整页跳转」才用离线页顶；脚本 / 图片请求返回离线页会污染资源
        return caches.match(OFFLINE_URL);
      }
      return new Response('离线，且本地没有这一份缓存', {
        status: 503,
        headers: { 'Content-Type': 'text/plain; charset=utf-8' },
      });
    });
  });
}

/**
 * 这个响应值不值得存一份。
 *
 * 按 pathname 存而不是按整个 Request：同一个页面从不同入口进来（/games 与 /games.html、
 * 带不带查询串）会生成不同的 Request，按 Request 存会把同一份内容存好几份。
 */
function shouldCache(request, url, response) {
  if (!response || response.status !== 200 || response.type !== 'basic') return false;
  if (request.mode === 'navigate') return true;
  return url.pathname.indexOf('/assets/') === 0
    || url.pathname === '/manifest.webmanifest'
    || /\.html$/.test(url.pathname);
}
