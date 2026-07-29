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
const configExample = read("config.example.php");
const checkin = read("checkin.php");
const relogin = read("relogin.php");
const sessionInfo = read("session_info.php");
const enterQueue = read(path.join("DaVez", "entrar.php"));
const listQueue = read(path.join("DaVez", "listar.php"));
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
  ["session_info.php", sessionInfo],
  ["DaVez/entrar.php", enterQueue],
  ["DaVez/listar.php", listQueue],
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
  ["relogin.php", relogin],
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
  listQueue,
  /davez_require_http_method\('GET'\)/,
  "DaVez/listar.php não restringe leitura a GET."
);

process.stdout.write("endpoint_security_policy: OK\n");
