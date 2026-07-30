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

assert.match(
  source,
  /<a class="back-home" href="\/" aria-label="Voltar para a tela inicial">/,
  "O login administrativo deve permitir retorno semântico à tela inicial."
);
assert.match(
  source,
  /class="back-home-icon" aria-hidden="true">←<\/span>/,
  "O ícone do retorno deve ser decorativo."
);

assert.ok(htmlStart >= 0, "A interface autenticada deve existir.");

for (const endpoint of [
  '"admin.php"',
  '"DaVez/listar_admin.php?v=1"',
  '"DaVez/sair.php?v=1"',
  '"DaVez/reordenar.php?v=1"',
]) {
  assert.ok(interfaceSource.includes(endpoint), `Endpoint preservado: ${endpoint}`);
}
assert.doesNotMatch(
  inlineScript,
  /DaVez\/listar\.php\?v=/,
  "O painel não pode consumir a fila pública mínima."
);

for (const action of [
  "toggle_chamada",
  "limpar",
  "save_settings",
  "toggle_close",
  "add_manual",
  "atualizar_ordem",
  "apagar_relatorio",
  "issue_checkin_ticket",
  "issue_recovery_ticket",
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

for (const id of ["lat", "lng", "raio", "manualNome", "manualObs"]) {
  assert.match(interfaceSource, new RegExp(`<label[^>]+for="${id}"`));
  assert.match(interfaceSource, new RegExp(`<input[^>]+id="${id}"`));
}
assert.doesNotMatch(interfaceSource, /<label[^>]+for="token"/i);
assert.doesNotMatch(interfaceSource, /<input[^>]+id="token"/i);
assert.doesNotMatch(interfaceSource, /id="tokenDisplay"|id="contador"/);
assert.doesNotMatch(inlineScript, /f\.append\(["']token["']/);

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
assert.match(interfaceSource, /data-action="issue-recovery" data-id="\$\{id\}"/);
assert.match(inlineScript, /window\.Sortable/);
assert.match(inlineScript, /typeof window\.Sortable\.create/);

assert.match(interfaceSource, /<table class="report-table">/);
assert.match(interfaceSource, /<caption>/);
assert.equal((interfaceSource.match(/<th scope="col">/g) || []).length, 5);
assert.match(interfaceSource, /class="table-scroll" tabindex="0" role="region"/);

assert.match(interfaceSource, /data-state="loading"/);
assert.match(interfaceSource, /data-state="empty"/);
assert.match(inlineScript, /data-state="\$\{tone\}"/);

assert.match(interfaceSource, /id="individual-codes-title">Códigos individuais<\/h2>/);
assert.match(interfaceSource, /id="btnIssueCheckinTicket"/);
assert.match(
  interfaceSource,
  /id="issuedTicketResult" role="status"[\s\S]*?aria-live="polite"[\s\S]*?hidden/
);
assert.match(interfaceSource, /id="issuedAccessCode"/);
assert.match(interfaceSource, /id="issuedTicketExpiry"/);
assert.match(interfaceSource, /id="btnCopyTicket"/);
assert.match(interfaceSource, /id="btnHideTicket"/);
assert.match(interfaceSource, /QR externo não faz parte deste lote/);
assert.match(inlineScript, /action:'issue_checkin_ticket'/);
assert.match(inlineScript, /action:'issue_recovery_ticket'/);
assert.match(inlineScript, /_csrf:CSRF_TOKEN/);
assert.match(inlineScript, /navigator\.clipboard\.writeText\(code\)/);
assert.match(inlineScript, /openAdminDialog\(\{[\s\S]*?Emitir código de recovery\?/);

process.stdout.write("admin_interface_contract: OK\n");
