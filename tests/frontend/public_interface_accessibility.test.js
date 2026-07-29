const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

const projectRoot = path.resolve(__dirname, "..", "..");
const htmlPath = path.join(projectRoot, "index.html");
const manifestPath = path.join(projectRoot, "manifest.json");
const html = fs.readFileSync(htmlPath, "utf8");
const manifest = JSON.parse(fs.readFileSync(manifestPath, "utf8"));
const styleBlocks = [...html.matchAll(/<style(?:\s[^>]*)?>([\s\S]*?)<\/style>/g)];

assert.equal(styleBlocks.length, 1, "a interface deve manter um único sistema de estilos");
const css = styleBlocks[0][1];

function requirePattern(pattern, message) {
  assert.match(html, pattern, message);
}

function relativeLuminance(hex) {
  const channels = hex
    .replace("#", "")
    .match(/.{2}/g)
    .map(value => Number.parseInt(value, 16) / 255)
    .map(value => value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4);

  return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
}

function contrastRatio(first, second) {
  const light = Math.max(relativeLuminance(first), relativeLuminance(second));
  const dark = Math.min(relativeLuminance(first), relativeLuminance(second));
  return (light + 0.05) / (dark + 0.05);
}

function pngDimensions(filePath) {
  const file = fs.readFileSync(filePath);
  const pngSignature = "89504e470d0a1a0a";

  assert.equal(file.subarray(0, 8).toString("hex"), pngSignature, `${filePath} deve ser PNG`);

  return {
    width: file.readUInt32BE(16),
    height: file.readUInt32BE(20),
  };
}

// Estrutura semântica e formulários.
requirePattern(/<a class="skip-link" href="#conteudo-principal">/, "deve oferecer atalho para o conteúdo");
requirePattern(/<main id="conteudo-principal"[^>]*tabindex="-1">/, "o destino do atalho deve aceitar foco");
requirePattern(/<h1 id="pageTitle">/, "a página deve ter título principal identificável");
requirePattern(/<label for="nome">/, "o campo de nome deve ter label real");
requirePattern(/<label for="token">/, "o campo de token deve ter label real");
requirePattern(/id="nome"[\s\S]*?aria-describedby="nomeHint"[\s\S]*?required/, "nome deve ter ajuda associada e ser obrigatório");
requirePattern(/id="token"[\s\S]*?aria-describedby="tokenHint"[\s\S]*?required/, "token deve ter ajuda associada e ser obrigatório");

// Feedback acessível e estados de aplicação.
requirePattern(/id="status"[^>]*role="status"[^>]*aria-live="polite"/, "status deve ser anunciado");
requirePattern(/id="appState"[^>]*role="status"[^>]*aria-live="polite"/, "conectividade deve ser anunciada");
requirePattern(/id="davezState"[^>]*role="status"[^>]*aria-live="polite"/, "estado da fila deve ser anunciado");
requirePattern(/window\.addEventListener\("offline", updateConnectionState\)/, "deve observar perda de conexão");
requirePattern(/showServiceWorkerUpdate\(worker\)/, "deve exibir atualização disponível");
requirePattern(/window\.addEventListener\("appinstalled"/, "deve tratar instalação concluída");

// Modal com nome, descrição, foco contido e fechamento por Escape.
requirePattern(/id="modalDialog"[\s\S]*?role="dialog"[\s\S]*?aria-modal="true"[\s\S]*?aria-labelledby="modalTitle"[\s\S]*?aria-describedby="modalBody"/, "modal deve expor relações ARIA");
requirePattern(/function getModalFocusableElements\(\)/, "modal deve identificar controles focalizáveis");
requirePattern(/if \(e\.key === "Escape"\)/, "modal deve fechar com Escape");
requirePattern(/document\.body\.classList\.add\("modal-open"\)/, "modal deve controlar o fundo");

// Responsividade, foco e preferência de movimento.
requirePattern(/min-height:\s*100dvh/, "layout deve usar viewport dinâmica");
requirePattern(/env\(safe-area-inset-bottom\)/, "layout deve respeitar safe areas");
requirePattern(/:focus-visible/, "controles devem ter foco visível");
requirePattern(/@media \(max-width:\s*23\.75rem\)/, "deve haver ajuste explícito para 380px ou menos");
requirePattern(/@media \(prefers-color-scheme:\s*dark\)/, "deve oferecer dark mode nativo");
requirePattern(/@media \(prefers-reduced-motion:\s*reduce\)/, "deve respeitar redução de movimento");
assert.doesNotMatch(html, /z-index:\s*999\d/, "não deve usar z-index arbitrário");

// Direção visual premium sem dependências externas ou antipadrões do projeto.
requirePattern(/class="card-shell"/, "superfície principal deve usar double-bezel moderado");
requirePattern(/@keyframes surface-reveal/, "entrada inicial deve usar transform e opacidade");
requirePattern(/cubic-bezier\(0\.32,\s*0\.72,\s*0,\s*1\)/, "microinterações devem usar curva física");
assert.doesNotMatch(html, /\b(Inter|Roboto|Arial|Helvetica|Open Sans)\b/i, "não deve usar fontes genéricas banidas");
assert.doesNotMatch(html, /\bease-in-out\b|\blinear\s*;/i, "não deve usar curvas de movimento genéricas");

for (const className of [
  "skip-link",
  "container",
  "card-shell",
  "card",
  "logo-top",
  "app-state",
  "app-state-dot",
  "app-state-content",
  "app-state-action",
  "field",
  "eyebrow",
  "lead",
  "field-hint",
  "status",
  "small",
  "pwa-box",
  "pwa-title",
  "pwa-text",
  "pwa-btn",
  "ios",
  "wait-box",
  "wait-header",
  "wait-icon",
  "wait-headtext",
  "wait-title",
  "wait-text",
  "wait-pos",
  "lbl",
  "num",
  "davez-box",
  "davez-top",
  "davez-icon",
  "davez-title",
  "davez-desc",
  "davez-grid",
  "davez-col",
  "davez-lbl",
  "davez-num",
  "davez-after",
  "davez-state",
  "wait-note",
  "btn-success",
  "mini-link",
  "modal-overlay",
  "modal",
  "modal-header",
  "badge",
  "modal-title",
  "modal-body",
  "modal-actions",
  "fab-refresh",
  "fab-refresh-icon",
  "fab-refresh-text",
  "btn",
  "primary",
  "ghost",
  "pos-card",
  "pos-icon",
  "pos-meta",
  "pos-label",
  "pos-number",
  "pos-sub",
  "show",
  "despachado",
  "ok",
  "err",
  "warn",
  "info",
]) {
  assert.match(css, new RegExp(`\\.${className}(?![\\w-])`), `classe .${className} deve permanecer estilizada`);
}

// Contratos existentes continuam estáveis.
for (const endpoint of [
  'const CHECKIN_URL = "checkin.php?v=3";',
  'const DAVEZ_ENTER_URL = "DaVez/entrar.php?v=1";',
  'const DAVEZ_LIST_URL = "DaVez/listar.php?v=1";',
  'const RELOGIN_URL = "relogin.php?v=1";',
  'const SESSION_INFO_URL = "session_info.php?v=1";',
]) {
  assert.ok(html.includes(endpoint), `endpoint preservado: ${endpoint}`);
}

for (const id of [
  "nome",
  "token",
  "btn",
  "status",
  "formBox",
  "waitBox",
  "btnDaVez2",
  "btnRefreshFloat",
]) {
  const matches = html.match(new RegExp(`id="${id}"`, "g")) || [];
  assert.equal(matches.length, 1, `id ${id} deve continuar único`);
}

assert.ok(!html.includes("await caches.keys()"), "a interface não deve apagar todos os caches do domínio");
assert.doesNotMatch(html, /id="btnInstalar(App|IOS)"[^>]*style=/, "botões de instalação não devem usar estilo inline");

// JavaScript embarcado deve continuar sintaticamente válido.
const scriptMatch = html.match(/<script>([\s\S]*?)<\/script>/);
assert.ok(scriptMatch, "script principal não encontrado");
new vm.Script(scriptMatch[1], { filename: "index.html:inline-script.js" });

// Cores essenciais devem atingir WCAG AA para texto normal.
assert.ok(contrastRatio("#164fc9", "#ffffff") >= 4.5, "botão azul deve ter contraste AA com texto branco");
assert.ok(contrastRatio("#0b1838", "#f4f6fa") >= 4.5, "texto principal deve ter contraste AA no canvas");
assert.ok(contrastRatio("#5d667a", "#ffffff") >= 4.5, "texto secundário deve ter contraste AA na superfície");
assert.ok(contrastRatio("#3262d4", "#ffffff") >= 4.5, "CTA do dark mode deve ter contraste AA");
assert.ok(contrastRatio("#70d5aa", "#07101f") >= 4.5, "CTA de sucesso do dark mode deve ter contraste AA");

// Manifesto e dimensões reais dos ícones devem concordar.
assert.equal(manifest.name, "DaVez — Fila de Motoboys");
assert.equal(manifest.short_name, "DaVez");
assert.equal(manifest.background_color, "#f4f6fa");
assert.equal(manifest.theme_color, "#f4f6fa");

for (const icon of manifest.icons) {
  const dimensions = pngDimensions(path.join(projectRoot, icon.src.replace(/^\//, "")));
  assert.equal(icon.sizes, `${dimensions.width}x${dimensions.height}`, `${icon.src} deve declarar sua dimensão real`);
}

console.log("public_interface_accessibility_test: OK");
