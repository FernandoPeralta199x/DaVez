const fs = require("node:fs");
const path = require("node:path");

const ROOT = path.resolve(__dirname, "..", "..");
const SELF = "tests/security/secret_hygiene_policy.test.js";
const MAX_TEXT_BYTES = 2 * 1024 * 1024;

const EXCLUDED_DIRECTORIES = new Set([
  ".git",
  ".private",
  ".codex",
  ".agents",
  "artifacts",
  "backup",
  "backups",
  "coverage",
  "data",
  "dumps",
  "logs",
  "node_modules",
  "reports",
  "secrets",
  "storage",
  "temp",
  "tmp",
  "vendor",
]);

const ALLOWED_EXTENSIONS = new Set([
  ".cjs",
  ".conf",
  ".css",
  ".example",
  ".html",
  ".ini",
  ".js",
  ".json",
  ".md",
  ".mjs",
  ".php",
  ".ps1",
  ".sh",
  ".sql",
  ".svg",
  ".toml",
  ".xml",
  ".yaml",
  ".yml",
]);

const ALLOWED_ROOT_DOCUMENTS = new Set([
  "AGENTS.md",
  "CONTRIBUTING.md",
  "README.md",
  "SECURITY.md",
]);

const REQUIRED_GITIGNORE_RULES = [
  {
    label: "config.php real",
    pattern: /^\/config\.php$/m,
  },
  {
    label: ".env real",
    pattern: /^\/\.env$/m,
  },
  {
    label: "variantes de .env",
    pattern: /^\/\.env\.\*$/m,
  },
  {
    label: "exceção segura para .env.example",
    pattern: /^!\/\.env\.example$/m,
  },
  {
    label: "diretório privado",
    pattern: /^\/\.private\/$/m,
  },
  {
    label: "logs de runtime",
    pattern: /^\/logs\/\*$/m,
  },
  {
    label: "relatórios de runtime",
    pattern: /^\/reports\/\*$/m,
  },
  {
    label: "arquivos .log",
    pattern: /^\*\.log$/m,
  },
  {
    label: "backups .bak",
    pattern: /^\*\.bak$/m,
  },
  {
    label: "dumps",
    pattern: /^\*\.dump$/m,
  },
  {
    label: "dumps SQL compactados",
    pattern: /^\*\.sql\.gz$/m,
  },
  {
    label: "artefatos gerados",
    pattern: /^\/artifacts\/$/m,
  },
];

const SECRET_KEY_PATTERN =
  /(?:password|passwd|pwd|secret|api[_-]?key|access[_-]?key|private[_-]?key|token)/i;

const DIRECT_SECRET_SIGNATURES = [
  {
    label: "header de chave privada",
    regex:
      /-----BEGIN (?:RSA |EC |DSA |OPENSSH )?PRIVATE KEY-----/g,
  },
  {
    label: "token clássico do GitHub",
    regex: /\bgh[pousr]_[A-Za-z0-9]{36,255}\b/g,
  },
  {
    label: "fine-grained token do GitHub",
    regex: /\bgithub_pat_[A-Za-z0-9_]{50,255}\b/g,
  },
  {
    label: "AWS access key",
    regex: /\b(?:AKIA|ASIA)[A-Z0-9]{16}\b/g,
  },
];

const DATABASE_URL_PATTERN =
  /\b(?:mysql|mariadb|postgres|postgresql|mongodb|mongodb\+srv):\/\/([^:\s/@]+):([^@\s/]+)@/gi;

function normalizeRelative(filePath) {
  return path.relative(ROOT, filePath).split(path.sep).join("/");
}

function isAllowedFile(filePath) {
  const relative = normalizeRelative(filePath);
  const baseName = path.basename(filePath);

  if (
    relative === SELF
    || baseName === "config.php"
    || baseName.endsWith(".local.php")
    || (
      /^config\..+\.php$/i.test(baseName)
      && baseName !== "config.example.php"
    )
  ) {
    return false;
  }

  if (
    baseName.startsWith(".env")
    && baseName !== ".env.example"
  ) {
    return false;
  }

  if (baseName === ".gitignore" || baseName === ".env.example") {
    return true;
  }

  const directoryName = path.dirname(relative);
  const extension = path.extname(baseName).toLowerCase();

  if (!ALLOWED_EXTENSIONS.has(extension)) {
    return false;
  }

  if (
    directoryName === "."
    && extension === ".md"
    && !ALLOWED_ROOT_DOCUMENTS.has(baseName)
  ) {
    return false;
  }

  return true;
}

function collectAllowedFiles(directory, files = []) {
  for (const entry of fs.readdirSync(directory, {
    withFileTypes: true,
  })) {
    if (entry.isSymbolicLink()) {
      continue;
    }

    const fullPath = path.join(directory, entry.name);

    if (entry.isDirectory()) {
      if (EXCLUDED_DIRECTORIES.has(entry.name.toLowerCase())) {
        continue;
      }

      collectAllowedFiles(fullPath, files);
      continue;
    }

    if (!entry.isFile() || !isAllowedFile(fullPath)) {
      continue;
    }

    const size = fs.statSync(fullPath).size;

    if (size <= MAX_TEXT_BYTES) {
      files.push(fullPath);
    }
  }

  return files;
}

function lineNumberAt(source, offset) {
  return source.slice(0, offset).split(/\r?\n/).length;
}

function isPlaceholder(value) {
  const trimmed = value.trim();

  if (trimmed === "") {
    return true;
  }

  if (
    /^\$\{[A-Za-z_][A-Za-z0-9_]*\}$/.test(trimmed)
    || /^\{\{[^{}]+\}\}$/.test(trimmed)
    || /^<[^<>]+>$/.test(trimmed)
  ) {
    return true;
  }

  const normalized = trimmed
    .replace(/^['"`]|['"`]$/g, "")
    .trim()
    .toLowerCase();

  return [
    "changeme",
    "change_me",
    "example",
    "exemplo",
    "placeholder",
    "replace_me",
    "replaceme",
    "valor-efemero",
    "valor_efemero",
    "your_api_key",
    "your_password",
    "your_secret",
    "your_token",
    "xxx",
    "xxxx",
  ].includes(normalized);
}

function isSyntheticTestValue(value, relativePath) {
  if (!relativePath.startsWith("tests/")) {
    return false;
  }

  const normalized = value
    .replace(/^['"`]|['"`]$/g, "")
    .toLowerCase();

  return /(?:synthetic|sintetic|teste|test[-_]|fake|dummy|nao-pode|não-pode|proibido|forbidden)/.test(
    normalized
  );
}

function isNonSecretMetadata(key, value) {
  const normalizedKey = key.toLowerCase();
  const normalizedValue = value
    .replace(/^['"`]|['"`]$/g, "")
    .trim();

  return normalizedKey === "token_data"
    && /^\d{4}-\d{2}-\d{2}$/.test(normalizedValue);
}

function extractLiteral(rawValue, allowUnquotedLiteral = false) {
  const trimmed = rawValue
    .trim()
    .replace(/[;,]\s*$/, "")
    .trim();

  if (trimmed === "") {
    return "";
  }

  const quoted = trimmed.match(/^(['"`])([\s\S]*?)\1$/);

  if (quoted) {
    return quoted[2];
  }

  if (
    /^(?:null|undefined|false)$/i.test(trimmed)
    || /^(?:getenv|env|config|password_hash|random_bytes)\s*\(/i.test(
      trimmed
    )
    || /^(?:process\.)?env[.\[]/i.test(trimmed)
    || trimmed.startsWith("$")
    || trimmed.startsWith("<?")
  ) {
    return null;
  }

  if (
    allowUnquotedLiteral
    && /^[A-Za-z0-9_./:+@=-]+$/.test(trimmed)
  ) {
    return trimmed;
  }

  return null;
}

function inspectSecretAssignments(source, relativePath, findings) {
  const lines = source.split(/\r?\n/);
  const assignmentPatterns = [
    {
      regex:
        /^\s*(?:export\s+)?([A-Z][A-Z0-9_]*)\s*=\s*(.*?)\s*$/,
      allowUnquotedLiteral: true,
    },
    {
      regex:
        /^\s*(?:const|let|var)?\s*\$?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*?)\s*$/,
      allowUnquotedLiteral: false,
    },
    {
      regex:
        /^\s*["']([A-Za-z_][A-Za-z0-9_-]*)["']\s*(?:=>|:)\s*(.*?)\s*$/,
      allowUnquotedLiteral: false,
    },
    {
      regex:
        /^\s*define\s*\(\s*["']([A-Za-z_][A-Za-z0-9_-]*)["']\s*,\s*(.*?)\)\s*;?\s*$/,
      allowUnquotedLiteral: false,
    },
  ];

  for (const [index, line] of lines.entries()) {
    for (const pattern of assignmentPatterns) {
      const match = line.match(pattern.regex);

      if (!match || !SECRET_KEY_PATTERN.test(match[1])) {
        continue;
      }

      const literal = extractLiteral(
        match[2],
        pattern.allowUnquotedLiteral
      );

      if (
        literal === null
        || isPlaceholder(literal)
        || isSyntheticTestValue(literal, relativePath)
        || isNonSecretMetadata(match[1], literal)
      ) {
        break;
      }

      findings.push(
        `${relativePath}:${index + 1}: atribuição literal suspeita para ${match[1]}`
      );
      break;
    }
  }
}

function inspectContent(filePath, findings) {
  const relativePath = normalizeRelative(filePath);
  const source = fs.readFileSync(filePath, "utf8");

  for (const signature of DIRECT_SECRET_SIGNATURES) {
    signature.regex.lastIndex = 0;

    for (
      let match = signature.regex.exec(source);
      match !== null;
      match = signature.regex.exec(source)
    ) {
      findings.push(
        `${relativePath}:${lineNumberAt(source, match.index)}: ${signature.label}`
      );
    }
  }

  DATABASE_URL_PATTERN.lastIndex = 0;

  for (
    let match = DATABASE_URL_PATTERN.exec(source);
    match !== null;
    match = DATABASE_URL_PATTERN.exec(source)
  ) {
    const password = match[2];

    if (!isPlaceholder(password)) {
      findings.push(
        `${relativePath}:${lineNumberAt(source, match.index)}: URL de banco contém credencial não vazia`
      );
    }
  }

  inspectSecretAssignments(source, relativePath, findings);
}

function inspectGitignore(findings) {
  const gitignorePath = path.join(ROOT, ".gitignore");

  if (!fs.existsSync(gitignorePath)) {
    findings.push(".gitignore: arquivo obrigatório ausente");
    return;
  }

  const source = fs.readFileSync(gitignorePath, "utf8");

  for (const rule of REQUIRED_GITIGNORE_RULES) {
    if (!rule.pattern.test(source)) {
      findings.push(
        `.gitignore: proteção ausente para ${rule.label}`
      );
    }
  }
}

function inspectExampleEnvironment(findings) {
  const examplePath = path.join(ROOT, ".env.example");

  if (!fs.existsSync(examplePath)) {
    findings.push(".env.example: arquivo de exemplo obrigatório ausente");
    return;
  }

  const lines = fs.readFileSync(examplePath, "utf8").split(/\r?\n/);

  for (const [index, line] of lines.entries()) {
    if (/^\s*(?:#|$)/.test(line)) {
      continue;
    }

    const match = line.match(
      /^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*?)\s*$/
    );

    if (!match) {
      findings.push(
        `.env.example:${index + 1}: linha de configuração inválida`
      );
      continue;
    }

    if (
      SECRET_KEY_PATTERN.test(match[1])
      && !isPlaceholder(match[2])
    ) {
      findings.push(
        `.env.example:${index + 1}: ${match[1]} deve ficar vazio ou usar placeholder`
      );
    }
  }
}

function inspectConfigExample(findings) {
  const examplePath = path.join(ROOT, "config.example.php");

  if (!fs.existsSync(examplePath)) {
    findings.push(
      "config.example.php: arquivo de exemplo obrigatório ausente"
    );
    return;
  }

  inspectContent(examplePath, findings);
}

const findings = [];
const files = collectAllowedFiles(ROOT);

for (const filePath of files) {
  inspectContent(filePath, findings);
}

inspectGitignore(findings);
inspectExampleEnvironment(findings);
inspectConfigExample(findings);

if (findings.length > 0) {
  process.stderr.write(
    `secret_hygiene_policy: FALHOU\n${findings
      .map((finding) => `- ${finding}`)
      .join("\n")}\n`
  );
  process.exit(1);
}

process.stdout.write(
  `secret_hygiene_policy: OK (${files.length} arquivos textuais permitidos verificados)\n`
);
