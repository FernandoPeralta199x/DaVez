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
  "ticket_status",
]) {
  assert.ok(interfaceSource.includes(action), `Ação preservada: ${action}`);
}

assert.match(interfaceSource, /role="tablist"/);
assert.equal(
  (interfaceSource.match(/<button\b[^>]*role="tab"/g) || []).length,
  7
);
assert.equal(
  (interfaceSource.match(/<section\b[^>]*role="tabpanel"/g) || []).length,
  7
);
assert.match(
  interfaceSource,
  /id="tab-ranking" role="tab"[\s\S]*?aria-controls="ranking"[\s\S]*?data-tab="ranking"/
);
assert.match(
  interfaceSource,
  /id="ranking" class="section" role="tabpanel"[\s\S]*?aria-labelledby="tab-ranking"[\s\S]*?hidden/
);
assert.match(interfaceSource, /id="ranking-title">Ranking de Motoboys<\/h2>/);
assert.match(interfaceSource, /id="rankingBox"/);
assert.match(interfaceSource, /data-periodo="dia"[\s\S]*?data-periodo="semana"[\s\S]*?data-periodo="mes"/);
assert.match(inlineScript, /async function carregarRanking\(/);
// Regressão: o campo de configuração é carregado por carregarConfig() ao
// abrir a aba/após salvar — não pelo polling de 12s (que apagaria a digitação).
assert.match(inlineScript, /async function carregarConfig\(/);
assert.match(inlineScript, /if \(id === 'config'\) carregarConfig\(\)/);
assert.equal(
  (inlineScript.match(/getElementById\('lat'\)\.value\s*=/g) || []).length,
  1,
  "o campo de latitude só pode ser preenchido em um lugar (carregarConfig), não no polling"
);
assert.match(inlineScript, /admin\.php\?action=ranking/);
assert.match(inlineScript, /if \(id === 'ranking'\) carregarRanking/);
assert.match(
  interfaceSource,
  /id="tab-qrcode" role="tab"[\s\S]*?aria-controls="qrcode"[\s\S]*?data-tab="qrcode"/
);
assert.match(
  interfaceSource,
  /id="qrcode" class="section" role="tabpanel"[\s\S]*?aria-labelledby="tab-qrcode"[\s\S]*?hidden/
);
assert.match(
  interfaceSource,
  /id="tab-suporte" role="tab"[\s\S]*?aria-controls="suporte"[\s\S]*?data-tab="suporte"/
);
assert.match(
  interfaceSource,
  /id="suporte" class="section" role="tabpanel"[\s\S]*?aria-labelledby="tab-suporte"[\s\S]*?hidden/
);
assert.match(
  interfaceSource,
  /href="mailto:fernando\.augusto\.peralta@gmail\.com"/
);
assert.match(
  interfaceSource,
  /href="https:\/\/wa\.me\/5548992163264\?text=Ol%C3%A1%2C%20Fernando\.%20Preciso%20de%20suporte%20no%20DaVez\."/
);
assert.match(
  interfaceSource,
  /target="_blank" rel="noopener noreferrer"/
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

assert.match(interfaceSource, /id="permanent-qr-title">QR permanente de acesso<\/h2>/);
assert.match(interfaceSource, /id="permanentQrCanvas"/);
assert.match(interfaceSource, /id="btnDownloadPermanentQr"/);
assert.match(interfaceSource, /id="btnPrintPermanentQr"/);
assert.match(interfaceSource, /id="btnCopyPublicUrl"/);
assert.match(
  interfaceSource,
  /<script src="js\/qrcode-generator-1\.4\.4\.min\.js"><\/script>/
);
assert.match(interfaceSource, /id="individual-codes-title">Código e QR individual<\/h2>/);
assert.match(interfaceSource, /id="btnIssueCheckinTicket"/);
assert.match(
  interfaceSource,
  /id="issuedTicketResult"[\s\S]*?role="status"[\s\S]*?aria-live="polite"[\s\S]*?hidden/
);
assert.match(interfaceSource, /id="issuedAccessCode"/);
assert.match(interfaceSource, /id="issuedTicketExpiry"/);
assert.match(interfaceSource, /id="issuedTicketCountdown"/);
assert.match(interfaceSource, /id="issuedTicketState" data-state="active">Ativo/);
assert.match(interfaceSource, /id="individualQrCanvas"/);
assert.match(interfaceSource, /id="btnCopyTicket"/);
assert.match(interfaceSource, /id="btnRefreshTicket"/);
assert.match(interfaceSource, /id="btnHideTicket"/);
// O painel de codigo individual mora na aba Chamada, nao na aba QR Code.
assert.match(interfaceSource, /id="individualTicketCard"/);
assert.doesNotMatch(interfaceSource, /id="btnDownloadIndividualQr"/);
assert.doesNotMatch(interfaceSource, /id="btnPrintIndividualQr"/);
assert.doesNotMatch(interfaceSource, /QR externo não faz parte deste lote/);
assert.match(inlineScript, /function getPublicAppUrl\(\)/);
assert.match(inlineScript, /function getIndividualAccessUrl\(accessCode\)/);
assert.match(inlineScript, /publicUrl\.hash = new URLSearchParams/);
assert.match(inlineScript, /function renderQrToCanvas\(canvas, value, accessibleLabel\)/);
assert.match(inlineScript, /window\.qrcode\(0, 'M'\)/);
assert.match(inlineScript, /function downloadQrCanvas\(canvas, filename\)/);
assert.match(inlineScript, /function printQrCanvas\(canvas, title, value, instruction\)/);
assert.match(inlineScript, /async function refreshIndividualTicketStatus\(\)/);
assert.match(inlineScript, /acao:'ticket_status'/);
assert.match(inlineScript, /action:'issue_checkin_ticket'/);
assert.match(inlineScript, /action:'issue_recovery_ticket'/);
assert.match(inlineScript, /_csrf:CSRF_TOKEN/);
assert.match(inlineScript, /navigator\.clipboard\.writeText\(value\)/);
assert.match(inlineScript, /openAdminDialog\(\{[\s\S]*?Emitir código de recovery\?/);

// Painel de logs de erro/bug na aba Suporte.
assert.match(interfaceSource, /id="support-logs-title">Logs de erros e bugs<\/h2>/);
assert.match(interfaceSource, /id="supportLogBox"/);
assert.match(interfaceSource, /id="btnRefreshLogs"/);
assert.match(interfaceSource, /class="support-logs-icon"/);
assert.match(inlineScript, /async function carregarLogs\(\)/);
assert.match(inlineScript, /admin\.php\?action=logs/);
assert.match(inlineScript, /if \(id === 'suporte'\) carregarLogs\(\)/);

process.stdout.write("admin_interface_contract: OK\n");
