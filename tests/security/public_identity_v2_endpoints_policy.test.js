const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");

const root = path.resolve(__dirname, "..", "..");
const read = (relativePath) =>
  fs.readFileSync(path.join(root, relativePath), "utf8");

const checkin = read("checkin.php");
const relogin = read("relogin.php");
const sessionInfo = read("session_info.php");
const enterQueue = read(path.join("DaVez", "entrar.php"));
const listQueue = read(path.join("DaVez", "listar.php"));
const admin = read("admin.php");

assert.ok(
  fs.existsSync(path.join(root, "recover.php")),
  "A recuperação administrativa v2 deve possuir endpoint próprio."
);
assert.ok(
  fs.existsSync(path.join(root, "public_logout.php")),
  "A sessão pública deve possuir logout e revogação server-side."
);

assert.match(checkin, /\['nome', 'access_code', 'lat', 'lng'\]/);
assert.match(checkin, /davez_public_ticket_hash/);
assert.match(checkin, /loadTicketForUpdate/);
assert.match(checkin, /createSession/);
assert.match(checkin, /operational_date/);
assert.match(checkin, /isset\(\$_POST\['client_id'\]\)/);
assert.doesNotMatch(
  checkin,
  /\['nome', 'access_code', 'lat', 'lng',\s*'client_id'\]/
);
assert.doesNotMatch(checkin, /\(nome,\s*client_id,/);
assert.doesNotMatch(checkin, /REMOTE_ADDR|HTTP_USER_AGENT/);
assert.match(
  checkin,
  /SELECT chamada_aberta, lat_base, lng_base, raio\s+FROM settings/
);

assert.match(relogin, /legacy_relogin_disabled/);
assert.match(relogin, /\b410\b/);
assert.doesNotMatch(relogin, /FROM\s+checkins|settings|nome|token/i);

assert.match(sessionInfo, /identity_version['"]?\s*=>\s*2/);
assert.match(sessionInfo, /davez_authenticated_public_identity/);
assert.doesNotMatch(sessionInfo, /loadAndRotate|SettingsTokenCycle/);
assert.doesNotMatch(
  sessionInfo,
  /\b(?:INSERT|UPDATE|DELETE|REPLACE)\b/i,
  "session_info deve permanecer estritamente de leitura."
);

assert.match(enterQueue, /\['lat', 'lng'\]/);
assert.match(enterQueue, /davez_require_public_identity/);
assert.match(enterQueue, /checkin_id/);
assert.match(enterQueue, /isset\(\$_POST\['client_id'\]\)/);
assert.doesNotMatch(enterQueue, /\['lat', 'lng',\s*'client_id'\]/);
assert.match(
  enterQueue,
  /SELECT lat_base, lng_base, raio\s+FROM settings/
);

assert.match(listQueue, /davez_authenticated_public_identity/);
assert.match(listQueue, /identity_version['"]?\s*=>\s*2/);
assert.match(listQueue, /["']counts["']/);
assert.match(listQueue, /["']me["']/);
assert.doesNotMatch(listQueue, /client_id/);
assert.doesNotMatch(listQueue, /["']fila["']\s*=>/);

assert.ok(
  fs.existsSync(path.join(root, "DaVez", "listar_admin.php")),
  "A listagem completa deve possuir endpoint administrativo separado."
);
const adminListQueue = read(path.join("DaVez", "listar_admin.php"));
assert.match(adminListQueue, /davez_require_admin\(\)/);
assert.match(adminListQueue, /["']fila["']\s*=>/);

const recover = read("recover.php");
assert.match(recover, /\['access_code'\]/);
assert.match(recover, /purpose['"]?\]\s*!==\s*['"]recovery['"]/);
assert.match(recover, /revokeActiveSessions/);
assert.match(recover, /createSession/);
assert.doesNotMatch(recover, /\$_POST\[['"]nome['"]\]/);
assert.doesNotMatch(recover, /LOWER\s*\(\s*TRIM\s*\(\s*nome\s*\)\s*\)/i);

const logout = read("public_logout.php");
assert.match(logout, /davez_authenticated_public_identity/);
assert.match(logout, /revokeSession/);
assert.match(logout, /davez_clear_public_identity_cookie/);

assert.match(admin, /revokeActiveSessions\(/);
assert.match(
  admin,
  /DELETE FROM fila_da_vez[\s\S]*checkin_id=\?/,
  "Fechar um check-in v2 deve remover a fila pela identidade canônica."
);

process.stdout.write("public_identity_v2_endpoints_policy: OK\n");
