const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");

const adminPath = path.resolve(__dirname, "..", "..", "admin.php");
const source = fs.readFileSync(adminPath, "utf8");
const htmlStart = source.indexOf("<!DOCTYPE html>");
const interfaceSource = source.slice(htmlStart);
const inlineScript = [...interfaceSource.matchAll(/<script>([\s\S]*?)<\/script>/gi)]
  .map((match) => match[1])
  .join("\n");

assert.ok(htmlStart >= 0, "A interface autenticada deve existir.");

for (const endpoint of [
  '"admin.php"',
  '"DaVez/listar.php?v=1"',
  '"DaVez/sair.php?v=1"',
  '"DaVez/reordenar.php?v=1"',
]) {
  assert.ok(interfaceSource.includes(endpoint), `Endpoint preservado: ${endpoint}`);
}

for (const action of [
  "toggle_chamada",
  "limpar",
  "save_settings",
  "toggle_close",
  "add_manual",
  "atualizar_ordem",
  "apagar_relatorio",
]) {
  assert.ok(interfaceSource.includes(action), `Ação preservada: ${action}`);
}

assert.match(interfaceSource, /role="tablist"/);
assert.equal(
  (interfaceSource.match(/<button\b[^>]*role="tab"/g) || []).length,
  4
);
assert.equal(
  (interfaceSource.match(/<section\b[^>]*role="tabpanel"/g) || []).length,
  4
);
assert.match(inlineScript, /ArrowLeft/);
assert.match(inlineScript, /ArrowRight/);
assert.match(inlineScript, /aria-selected/);

for (const id of ["token", "lat", "lng", "raio", "manualNome", "manualObs"]) {
  assert.match(interfaceSource, new RegExp(`<label[^>]+for="${id}"`));
  assert.match(interfaceSource, new RegExp(`<input[^>]+id="${id}"`));
}

const buttonTags = [...interfaceSource.matchAll(/<button\b[^>]*>/gi)].map(
  (match) => match[0]
);
assert.ok(buttonTags.length > 0, "A interface deve conter botões.");
for (const tag of buttonTags) {
  assert.match(tag, /\btype="(?:button|submit)"/i, `Botão sem type: ${tag}`);
}

assert.match(interfaceSource, /@media \(prefers-color-scheme:dark\)/);
assert.match(interfaceSource, /@media \(prefers-reduced-motion:reduce\)/);
assert.match(interfaceSource, /:focus-visible/);
assert.match(interfaceSource, /min-height:44px/);
assert.match(interfaceSource, /role="status"[^>]+aria-live="polite"/);
assert.match(interfaceSource, /role="dialog"[^>]+aria-modal="true"/);
assert.doesNotMatch(inlineScript, /\b(?:alert|confirm)\s*\(/);
assert.doesNotMatch(interfaceSource, /\sonclick=/i);
assert.doesNotMatch(interfaceSource, /\sstyle=/i);

assert.match(inlineScript, /async function fetchJsonAdmin/);
assert.match(inlineScript, /authentication_required/);
assert.match(inlineScript, /response\.status === 401/);
assert.equal(
  (inlineScript.match(/\bfetch\s*\(/g) || []).length,
  1,
  "Toda requisição deve passar por fetchJsonAdmin."
);

assert.match(interfaceSource, /data-action="move-main"/);
assert.match(interfaceSource, /data-action="move-davez"/);
assert.match(inlineScript, /window\.Sortable/);
assert.match(inlineScript, /typeof window\.Sortable\.create/);

assert.match(interfaceSource, /<table class="report-table">/);
assert.match(interfaceSource, /<caption>/);
assert.equal((interfaceSource.match(/<th scope="col">/g) || []).length, 5);
assert.match(interfaceSource, /class="table-scroll" tabindex="0" role="region"/);

assert.match(interfaceSource, /data-state="loading"/);
assert.match(interfaceSource, /data-state="empty"/);
assert.match(inlineScript, /data-state="\$\{tone\}"/);

process.stdout.write("admin_interface_contract: OK\n");
