const assert = require("node:assert/strict");
const {
  CACHE_NAME,
  STATIC_ASSET_PATHS,
  REQUEST_STRATEGY,
  classifyRequest,
  registerServiceWorkerEvents,
} = require("../../service-worker.js");

const scopeUrl = "https://example.test/app/";

function request(path, options = {}) {
  return {
    method: options.method || "GET",
    mode: options.mode || "cors",
    url: new URL(path, scopeUrl).href,
  };
}

assert.equal(CACHE_NAME, "motoboys-static-v7");

for (const assetPath of STATIC_ASSET_PATHS) {
  assert.equal(
    classifyRequest(request(assetPath), scopeUrl),
    REQUEST_STRATEGY.CACHE_FIRST_STATIC,
    `${assetPath} deve usar cache estático`
  );
}

assert.equal(
  classifyRequest(request("./index.html?v=2"), scopeUrl),
  REQUEST_STRATEGY.CACHE_FIRST_STATIC,
  "query string não deve retirar um asset conhecido da allowlist"
);

assert.equal(
  classifyRequest(request("./index.html", { mode: "navigate" }), scopeUrl),
  REQUEST_STRATEGY.NETWORK_FIRST_NAVIGATION,
  "home pública deve usar rede com fallback offline"
);

for (const privateNavigation of ["./admin.php", "./fila", "./rota-inexistente"]) {
  assert.equal(
    classifyRequest(request(privateNavigation, { mode: "navigate" }), scopeUrl),
    REQUEST_STRATEGY.NETWORK_ONLY,
    `${privateNavigation} não pode receber a home pública como fallback offline`
  );
}

assert.equal(
  classifyRequest(
    {
      method: "GET",
      mode: "navigate",
      url: "https://outside.example.test/app/index.html",
    },
    scopeUrl
  ),
  REQUEST_STRATEGY.NETWORK_ONLY,
  "navegação de outra origem não pode ser interceptada"
);

for (const dynamicPath of [
  "./checkin.php",
  "./recover.php",
  "./public_logout.php",
  "./relogin.php",
  "./session_info.php",
  "./admin.php",
  "./DaVez/listar.php",
  "./DaVez/listar_admin.php",
  "./DaVez/entrar.php",
  "./logs/checkin.log",
  "./reports/relatorio.html",
]) {
  assert.equal(
    classifyRequest(request(dynamicPath), scopeUrl),
    REQUEST_STRATEGY.NETWORK_ONLY,
    `${dynamicPath} não pode ser interceptado nem armazenado`
  );
}

assert.equal(
  classifyRequest(
    {
      method: "GET",
      mode: "cors",
      url: "https://cdn.example.test/library.js",
    },
    scopeUrl
  ),
  REQUEST_STRATEGY.NETWORK_ONLY,
  "origens externas não podem entrar no cache da aplicação"
);

assert.equal(
  classifyRequest(request("./checkin.php", { method: "POST" }), scopeUrl),
  REQUEST_STRATEGY.NETWORK_ONLY,
  "métodos não GET nunca podem ser interceptados"
);

for (const forbiddenSegment of [".php", "/logs/", "/reports/"]) {
  assert.equal(
    STATIC_ASSET_PATHS.some(assetPath => assetPath.includes(forbiddenSegment)),
    false,
    `${forbiddenSegment} não pode aparecer na allowlist`
  );
}

async function testFetchEventBoundary() {
  const listeners = {};
  const cachedResponse = { source: "cache" };
  const deletedCaches = [];
  let clientsClaimed = false;
  let skipWaitingCalls = 0;

  global.caches = {
    keys: async () => [
      "motoboys-pwa-v1",
      "motoboys-static-v2",
      "motoboys-static-v3",
      "motoboys-static-v4",
      "motoboys-static-v5",
      "motoboys-static-v7",
      "cache-de-outra-aplicacao",
    ],
    delete: async cacheName => {
      deletedCaches.push(cacheName);
      return true;
    },
    open: async () => ({
      addAll: async () => {},
      match: async () => cachedResponse,
      put: async () => {},
    }),
  };
  global.fetch = async () => ({
    ok: true,
    type: "basic",
    clone() {
      return this;
    },
  });

  const fakeServiceWorkerScope = {
    registration: { scope: scopeUrl },
    clients: {
      claim: async () => {
        clientsClaimed = true;
      },
    },
    skipWaiting: async () => {
      skipWaitingCalls += 1;
    },
    addEventListener(type, handler) {
      listeners[type] = handler;
    },
  };

  registerServiceWorkerEvents(fakeServiceWorkerScope);

  let installation;
  listeners.install({
    waitUntil(promise) {
      installation = promise;
    },
  });
  await installation;
  assert.equal(
    skipWaitingCalls,
    0,
    "instalação não deve trocar o worker sem confirmação do usuário"
  );

  let messageUpdate;
  listeners.message({
    data: { action: "skipWaiting" },
    waitUntil(promise) {
      messageUpdate = promise;
    },
  });
  await messageUpdate;
  assert.equal(
    skipWaitingCalls,
    1,
    "confirmação do usuário deve liberar o worker em espera"
  );

  let activation;
  listeners.activate({
    waitUntil(promise) {
      activation = promise;
    },
  });
  await activation;
  assert.deepEqual(
    deletedCaches,
    [
      "motoboys-pwa-v1",
      "motoboys-static-v2",
      "motoboys-static-v3",
      "motoboys-static-v4",
      "motoboys-static-v5",
    ],
    "a ativação deve remover o cache antigo sem apagar caches alheios"
  );
  assert.equal(
    clientsClaimed,
    true,
    "o novo Service Worker deve assumir os clientes após a limpeza"
  );

  let dynamicResponse;
  listeners.fetch({
    request: request("./admin.php"),
    respondWith(response) {
      dynamicResponse = response;
    },
  });
  assert.equal(
    dynamicResponse,
    undefined,
    "a fronteira fetch não pode interceptar respostas dinâmicas"
  );

  let staticResponse;
  listeners.fetch({
    request: request("./manifest.json"),
    respondWith(response) {
      staticResponse = response;
    },
  });
  assert.equal(
    await staticResponse,
    cachedResponse,
    "a fronteira fetch deve atender assets conhecidos pelo cache estático"
  );

  let navigationResponse;
  listeners.fetch({
    request: request("./index.html", { mode: "navigate" }),
    respondWith(response) {
      navigationResponse = response;
    },
  });
  assert.equal(
    (await navigationResponse).ok,
    true,
    "navegação online deve preservar a resposta da rede"
  );

  global.fetch = async () => {
    throw new Error("offline");
  };

  let offlineNavigationResponse;
  listeners.fetch({
    request: request("./index.html", { mode: "navigate" }),
    respondWith(response) {
      offlineNavigationResponse = response;
    },
  });
  assert.equal(
    await offlineNavigationResponse,
    cachedResponse,
    "navegação offline deve preservar o fallback para index.html"
  );

  delete global.caches;
  delete global.fetch;
}

testFetchEventBoundary()
  .then(() => {
    console.log("service_worker_cache_policy.test.js: OK");
  })
  .catch(error => {
    console.error(error);
    process.exitCode = 1;
  });
