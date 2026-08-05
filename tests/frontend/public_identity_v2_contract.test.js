const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

const projectRoot = path.resolve(__dirname, "..", "..");
const indexPath = path.join(projectRoot, "index.html");
const serviceWorkerPath = path.join(projectRoot, "service-worker.js");
const html = fs.readFileSync(indexPath, "utf8");
const serviceWorker = fs.readFileSync(serviceWorkerPath, "utf8");
const scriptBlocks = [...html.matchAll(/<script>([\s\S]*?)<\/script>/g)]
  .map(match => match[1]);

assert.ok(scriptBlocks.length >= 2, "scripts de tema e identidade pública não encontrados");
const script = scriptBlocks.at(-1);
new vm.Script(script, { filename: "index.html:identity-v2.js" });

for (const contract of [
  'const CHECKIN_URL = "checkin.php?v=4";',
  'const DAVEZ_ENTER_URL = "DaVez/entrar.php?v=2";',
  'const DAVEZ_LIST_URL = "DaVez/listar.php?v=2";',
  'const RECOVER_URL = "recover.php?v=1";',
  'const PUBLIC_LOGOUT_URL = "public_logout.php?v=1";',
  'const SESSION_INFO_URL = "session_info.php?v=2";',
]) {
  assert.ok(script.includes(contract), `contrato ausente: ${contract}`);
}

assert.match(html, /<label for="accessCode">Código individual<\/label>/);
assert.match(
  html,
  /id="accessCode"[\s\S]*?name="access_code"[\s\S]*?autocomplete="one-time-code"[\s\S]*?maxlength="9"/
);
assert.match(html, /id="btnRecover"[\s\S]*?onclick="recoverAccess\(\)"/);
assert.match(script, /form\.append\("access_code", accessCode\)/);
assert.match(html, /id="btnPublicLogout"/);
assert.match(script, /fetchPublicJson\(PUBLIC_LOGOUT_URL/);
assert.match(script, /async function recoverAccess\(\)/);
assert.match(script, /function applyAccessCodeFromFragment\(\)/);
assert.match(
  script,
  /new URLSearchParams\(fragment\)[\s\S]*?fragmentParams\.get\("access_code"\)/
);
assert.match(script, /window\.history\.replaceState\(null, document\.title/);
assert.match(script, /accessCodeField\.value = accessCode/);
assert.match(script, /applyAccessCodeFromFragment\(\)/);
assert.doesNotMatch(
  script,
  /new URLSearchParams\(window\.location\.search\)[\s\S]*?access_code/,
  "O código individual do QR não pode trafegar na query string."
);

assert.match(script, /credentials:\s*"same-origin"/);
assert.match(script, /cache:\s*"no-store"/);
assert.match(script, /Number\(data\.identity_version\) !== 2/);
assert.match(script, /data\.authenticated === true && data\.me/);
assert.match(script, /applySessionMe\(data\.me\)/);

assert.match(script, /await refreshSessionInfo\(\{silent:true\}\)/);
assert.match(script, /await refreshDaVezFromServer\(\)/);
// O laço periódico consulta apenas a fila; session_info sai do caminho quente.
assert.match(script, /setInterval\(pollDaVezQueue,\s*DAVEZ_POLL_INTERVAL_MS\)/);
assert.match(script, /const DAVEZ_POLL_INTERVAL_MS = \d+/);
assert.match(script, /document\.hidden/);
assert.match(script, /stopDaVezPolling\(\)/);
assert.match(script, /data\.next/);
assert.match(script, /data\.me/);
assert.match(script, /data\.counts/);
assert.match(script, /me\.is_next === true/);

assert.match(
  script,
  /enviarCheckin\(\{\s*nome,\s*access_code:\s*accessCode,\s*lat,\s*lng\s*\}\)/
);
assert.match(
  script,
  /fd\.append\("lat",[\s\S]*?fd\.append\("lng",[\s\S]*?fetchPublicJson\(DAVEZ_ENTER_URL/
);

for (const forbidden of [
  "sessionStorage",
  "client_id",
  "getClientId",
  "motoboy_token",
  "checked_in_active",
  "pending_checkin",
  "RELOGIN_URL",
  "relogin.php",
  'fd.append("nome"',
  'fd.append("token"',
  "data.fila",
]) {
  assert.ok(!script.includes(forbidden), `identidade legada presente: ${forbidden}`);
}
assert.doesNotMatch(
  script,
  /localStorage\.(?:getItem|setItem)\((?!THEME_STORAGE_KEY)/,
  "localStorage só pode persistir a preferência visual, nunca a identidade pública"
);

assert.match(serviceWorker, /const CACHE_NAME = "motoboys-static-v11";/);
assert.match(
  serviceWorker,
  /if \(!request \|\| request\.method !== "GET"\) \{\s*return REQUEST_STRATEGY\.NETWORK_ONLY;/
);
for (const endpoint of [
  "checkin.php",
  "recover.php",
  "session_info.php",
  "DaVez/entrar.php",
  "DaVez/listar.php",
]) {
  assert.ok(
    !serviceWorker.includes(`"${endpoint}"`),
    `service worker não deve precachear ${endpoint}`
  );
}

process.stdout.write("public_identity_v2_contract: OK\n");
