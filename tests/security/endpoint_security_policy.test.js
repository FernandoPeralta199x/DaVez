const fs = require("node:fs");
const path = require("node:path");

const root = path.resolve(__dirname, "..", "..");

function read(relativePath) {
  return fs.readFileSync(path.join(root, relativePath), "utf8");
}

function fail(message) {
  process.stderr.write(`${message}\n`);
  process.exit(1);
}

function requirePattern(source, pattern, message) {
  if (!pattern.test(source)) fail(message);
}

function forbidPattern(source, pattern, message) {
  if (pattern.test(source)) fail(message);
}

const admin = read("admin.php");
const publicPage = read("index.html");
const configExample = read("config.example.php");
const checkin = read("checkin.php");
const relogin = read("relogin.php");
const recover = read("recover.php");
const publicLogout = read("public_logout.php");
const sessionInfo = read("session_info.php");
const enterQueue = read(path.join("DaVez", "entrar.php"));
const listQueue = read(path.join("DaVez", "listar.php"));
const adminListQueue = read(path.join("DaVez", "listar_admin.php"));
const reorderQueue = read(path.join("DaVez", "reordenar.php"));
const exitQueue = read(path.join("DaVez", "sair.php"));

requirePattern(
  configExample,
  /ADMIN_PASSWORD_HASH/,
  "config.example.php não exige hash administrativo."
);
forbidPattern(
  configExample,
  /ADMIN_PASSWORD['"]/,
  "config.example.php ainda usa senha administrativa em texto puro."
);
forbidPattern(
  configExample,
  /PHP_AUTH_|Basic realm|ADMIN_PASS\b/,
  "config.example.php ainda contém autenticação Basic."
);

for (const [file, source] of [
  ["admin.php", admin],
  ["checkin.php", checkin],
  ["relogin.php", relogin],
  ["recover.php", recover],
  ["public_logout.php", publicLogout],
  ["session_info.php", sessionInfo],
  ["DaVez/entrar.php", enterQueue],
  ["DaVez/listar.php", listQueue],
  ["DaVez/listar_admin.php", adminListQueue],
  ["DaVez/reordenar.php", reorderQueue],
  ["DaVez/sair.php", exitQueue],
]) {
  requirePattern(
    source,
    /src[\\/]+Security[\\/]+Bootstrap\.php/,
    `${file} não carrega o bootstrap de segurança.`
  );
  requirePattern(
    source,
    /davez_install_safe_exception_handler\(\)/,
    `${file} não instala resposta segura para exceções.`
  );
  forbidPattern(
    source,
    /["']sql_error["']\s*=>|["']debug["']\s*=>/,
    `${file} ainda expõe detalhe interno na resposta.`
  );
}

requirePattern(
  admin,
  /davez_admin_authenticate\(/,
  "admin.php não usa autenticação por hash/sessão."
);
requirePattern(
  admin,
  /davez_require_csrf\(/,
  "admin.php não exige CSRF."
);
requirePattern(
  admin,
  /davez_admin_can_view_logs\(\)/,
  "admin.php não restringe os logs por papel do usuário."
);
requirePattern(
  admin,
  /\$adminCanViewLogs[\s\S]*?forbidden/,
  "o endpoint de logs não bloqueia quem não pode vê-los."
);
requirePattern(
  admin,
  /X-CSRF-Token/g,
  "O JavaScript administrativo não envia CSRF."
);
forbidPattern(
  admin,
  /admin\.php\?action=(?:toggle_chamada|limpar)/,
  "Uma mutação administrativa ainda usa GET."
);
forbidPattern(
  admin,
  /verify_manual_pass|manualPassCache|ADMIN_PASS\b|PHP_AUTH_/,
  "admin.php ainda contém o fluxo legado de senha administrativa."
);

for (const [file, source] of [
  ["checkin.php", checkin],
  ["recover.php", recover],
  ["public_logout.php", publicLogout],
  ["DaVez/entrar.php", enterQueue],
]) {
  requirePattern(
    source,
    /davez_require_public_request_context\(\)/,
    `${file} não exige contexto público anti-CSRF.`
  );
  requirePattern(
    source,
    /davez_rate_limit_consume\(/,
    `${file} não aplica rate limiting.`
  );
}

requirePattern(
  relogin,
  /legacy_relogin_disabled[\s\S]*\b410\b/,
  "relogin.php não encerra explicitamente o fluxo legado."
);

requirePattern(
  sessionInfo,
  /davez_bootstrap_public_request_context\(\)/,
  "session_info.php não cria o contexto público."
);

for (const [file, source] of [
  ["DaVez/reordenar.php", reorderQueue],
  ["DaVez/sair.php", exitQueue],
]) {
  requirePattern(
    source,
    /davez_require_http_method\('POST'\)/,
    `${file} não restringe a mutação a POST.`
  );
  requirePattern(
    source,
    /davez_require_admin\(\)/,
    `${file} não exige sessão administrativa.`
  );
  requirePattern(
    source,
    /davez_require_csrf\(\)/,
    `${file} não exige CSRF.`
  );
}

requirePattern(
  adminListQueue,
  /davez_require_http_method\('GET'\)/,
  "DaVez/listar_admin.php não restringe leitura a GET."
);
requirePattern(
  adminListQueue,
  /davez_require_admin\(\)/,
  "DaVez/listar_admin.php não exige sessão administrativa."
);

requirePattern(
  listQueue,
  /davez_require_http_method\('GET'\)/,
  "DaVez/listar.php não restringe leitura a GET."
);
requirePattern(
  listQueue,
  /davez_rate_limit_consume\(/,
  "DaVez/listar.php não aplica rate limiting no polling público."
);
forbidPattern(
  listQueue,
  /["']fila["']\s*=>|client_id/,
  "DaVez/listar.php ainda expõe a fila administrativa."
);

// Script de terceiro servido por CDN executaria código arbitrário na sessão
// autenticada se a origem externa fosse comprometida.
for (const [file, source] of [
  ["admin.php", admin],
  ["index.html", publicPage],
]) {
  forbidPattern(
    source,
    /<(?:script|link)[^>]*(?:src|href)=["']https?:\/\//i,
    `${file} carrega recurso de origem externa; hospede o arquivo em js/.`
  );
}

requirePattern(
  admin,
  /<script src="js\/sortable-1\.15\.0\.min\.js"><\/script>/,
  "admin.php não carrega o Sortable hospedado localmente."
);

process.stdout.write("endpoint_security_policy: OK\n");
