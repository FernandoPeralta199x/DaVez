const fs = require("node:fs");
const path = require("node:path");

const root = path.resolve(__dirname, "..", "..");
const sourceFiles = [
  "checkin.php",
  path.join("DaVez", "entrar.php"),
  "relogin.php",
];

const forbiddenCallPatterns = [
  {
    pattern: /log_event\s*\([^;]*\$_POST/s,
    message: "POST bruto ainda é enviado ao logger",
  },
  {
    pattern:
      /log_event\s*\([^;]*(token_recebido|token_esperado|mysql_error|client_id|["']nome["']\s*=>|["']lat["']\s*=>|["']lng["']\s*=>)/s,
    message: "campo sensível ainda é enviado ao logger",
  },
];

for (const relativePath of sourceFiles) {
  const source = fs.readFileSync(path.join(root, relativePath), "utf8");

  for (const { pattern, message } of forbiddenCallPatterns) {
    if (pattern.test(source)) {
      throw new Error(`${relativePath}: ${message}`);
    }
  }
}

const loggerSource = fs.readFileSync(path.join(root, "log.php"), "utf8");

for (const forbiddenServerField of ["REMOTE_ADDR", "HTTP_USER_AGENT"]) {
  if (loggerSource.includes(forbiddenServerField)) {
    throw new Error(
      `log.php: o logger ainda persiste ${forbiddenServerField}`
    );
  }
}

for (const expectedControl of [
  "sanitize_log_data",
  "APP_LOG_PATH",
  "FILE_APPEND | LOCK_EX",
]) {
  if (!loggerSource.includes(expectedControl)) {
    throw new Error(`log.php: controle ausente: ${expectedControl}`);
  }
}

console.log("logging_policy.test.js: OK");
