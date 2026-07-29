const CACHE_NAME = "motoboys-static-v2";

const STATIC_ASSET_PATHS = [
  "./index.html",
  "./manifest.json",
  "./icons/icon-192.png",
  "./icons/icon-512.png",
  "./img/logo.png",
];

const REQUEST_STRATEGY = Object.freeze({
  NETWORK_ONLY: "network-only",
  NETWORK_FIRST_NAVIGATION: "network-first-navigation",
  CACHE_FIRST_STATIC: "cache-first-static",
});

function normalizeUrlWithoutSearch(url) {
  const normalizedUrl = new URL(url);
  normalizedUrl.search = "";
  normalizedUrl.hash = "";
  return normalizedUrl.href;
}

function getStaticAssetUrls(scopeUrl) {
  return new Set(
    STATIC_ASSET_PATHS.map(assetPath =>
      normalizeUrlWithoutSearch(new URL(assetPath, scopeUrl).href)
    )
  );
}

function classifyRequest(request, scopeUrl) {
  if (!request || request.method !== "GET") {
    return REQUEST_STRATEGY.NETWORK_ONLY;
  }

  if (request.mode === "navigate") {
    return REQUEST_STRATEGY.NETWORK_FIRST_NAVIGATION;
  }

  const requestUrl = new URL(request.url);
  const scope = new URL(scopeUrl);

  if (requestUrl.origin !== scope.origin) {
    return REQUEST_STRATEGY.NETWORK_ONLY;
  }

  const staticAssetUrls = getStaticAssetUrls(scopeUrl);
  const normalizedRequestUrl = normalizeUrlWithoutSearch(requestUrl.href);

  return staticAssetUrls.has(normalizedRequestUrl)
    ? REQUEST_STRATEGY.CACHE_FIRST_STATIC
    : REQUEST_STRATEGY.NETWORK_ONLY;
}

async function handleNavigationRequest(request, scopeUrl) {
  try {
    return await fetch(request);
  } catch (error) {
    const cache = await caches.open(CACHE_NAME);
    const offlinePageUrl = new URL("./index.html", scopeUrl).href;
    const offlinePage = await cache.match(offlinePageUrl);

    return offlinePage || Response.error();
  }
}

async function handleStaticAssetRequest(request) {
  const cache = await caches.open(CACHE_NAME);
  const cachedResponse = await cache.match(request, { ignoreSearch: true });

  if (cachedResponse) {
    return cachedResponse;
  }

  const networkResponse = await fetch(request);

  if (networkResponse.ok && networkResponse.type === "basic") {
    await cache.put(request, networkResponse.clone());
  }

  return networkResponse;
}

function registerServiceWorkerEvents(serviceWorkerScope) {
  serviceWorkerScope.addEventListener("install", event => {
    event.waitUntil(
      caches
        .open(CACHE_NAME)
        .then(cache => cache.addAll(STATIC_ASSET_PATHS))
        .then(() => serviceWorkerScope.skipWaiting())
    );
  });

  serviceWorkerScope.addEventListener("activate", event => {
    event.waitUntil(
      caches
        .keys()
        .then(cacheNames =>
          Promise.all(
            cacheNames
              .filter(
                cacheName =>
                  cacheName.startsWith("motoboys-") && cacheName !== CACHE_NAME
              )
              .map(cacheName => caches.delete(cacheName))
          )
        )
        .then(() => serviceWorkerScope.clients.claim())
    );
  });

  serviceWorkerScope.addEventListener("fetch", event => {
    const strategy = classifyRequest(
      event.request,
      serviceWorkerScope.registration.scope
    );

    if (strategy === REQUEST_STRATEGY.NETWORK_FIRST_NAVIGATION) {
      event.respondWith(
        handleNavigationRequest(
          event.request,
          serviceWorkerScope.registration.scope
        )
      );
      return;
    }

    if (strategy === REQUEST_STRATEGY.CACHE_FIRST_STATIC) {
      event.respondWith(handleStaticAssetRequest(event.request));
    }
  });

  serviceWorkerScope.addEventListener("message", event => {
    if (event.data && event.data.action === "skipWaiting") {
      serviceWorkerScope.skipWaiting();
    }
  });
}

if (typeof self !== "undefined" && typeof self.addEventListener === "function") {
  registerServiceWorkerEvents(self);
}

if (typeof module !== "undefined" && module.exports) {
  module.exports = {
    CACHE_NAME,
    STATIC_ASSET_PATHS,
    REQUEST_STRATEGY,
    classifyRequest,
    getStaticAssetUrls,
    normalizeUrlWithoutSearch,
    registerServiceWorkerEvents,
  };
}
