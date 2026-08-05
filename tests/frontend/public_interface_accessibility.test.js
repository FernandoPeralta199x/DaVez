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
requirePattern(
  /<a class="admin-access" href="\/admin\.php"[\s\S]*?aria-label="Acessar a área administrativa"/,
  "deve oferecer acesso semântico e identificável à administração"
);
requirePattern(
  /class="theme-control" role="group" aria-label="Tema da tela"/,
  "o seletor de tema deve expor um grupo acessível"
);
requirePattern(
  /data-theme-option="light"[\s\S]*?aria-pressed="false">Claro<\/button>/,
  "o seletor deve oferecer tema claro com estado acessível"
);
requirePattern(
  /data-theme-option="dark"[\s\S]*?aria-pressed="false">Escuro<\/button>/,
  "o seletor deve oferecer tema escuro com estado acessível"
);
requirePattern(
  /name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"/,
  "o viewport deve ocupar a tela com safe areas no iOS"
);
requirePattern(
  /rel="apple-touch-icon" sizes="192x192" href="\/icons\/icon-192\.png"/,
  "o ícone da tela inicial do iOS deve declarar sua dimensão"
);
requirePattern(
  /name="apple-mobile-web-app-title" content="DaVez"/,
  "Android e iOS devem exibir o mesmo nome instalado"
);
requirePattern(/<label for="nome">/, "o campo de nome deve ter label real");
requirePattern(/<label for="accessCode">Código individual<\/label>/, "o código individual deve ter label real");
requirePattern(/id="nome"[\s\S]*?aria-describedby="nomeHint"[\s\S]*?required/, "nome deve ter ajuda associada e ser obrigatório");
requirePattern(/id="accessCode"[\s\S]*?autocomplete="one-time-code"[\s\S]*?maxlength="9"[\s\S]*?aria-describedby="accessCodeHint"[\s\S]*?required/, "código individual deve ter ajuda, autocomplete e limite corretos");
requirePattern(/id="btnRecover"[\s\S]*?aria-describedby="recoverHint"/, "recuperação deve ser acessível");
requirePattern(
  /<h2 id="pwaTitle" class="pwa-title">Instalar aplicativo<\/h2>/,
  "a instalação deve ter heading semântico e permanente"
);
requirePattern(
  /id="pwaText"[^>]*role="status"[^>]*aria-live="polite"[^>]*aria-atomic="true"[^>]*>Você pode instalar este sistema na tela inicial do celular para abrir como app\./,
  "a orientação de instalação deve ser visível e anunciável"
);
assert.ok(
  html.indexOf('id="pwaBox"') > html.indexOf('id="waitBox"'),
  "o painel PWA deve permanecer fora dos estados alternáveis do check-in"
);

// Feedback acessível e estados de aplicação.
requirePattern(/id="status"[^>]*role="status"[^>]*aria-live="polite"/, "status deve ser anunciado");
requirePattern(/id="appState"[^>]*role="status"[^>]*aria-live="polite"/, "conectividade deve ser anunciada");
requirePattern(/id="davezState"[^>]*role="status"[^>]*aria-live="polite"/, "estado da fila deve ser anunciado");
requirePattern(/window\.addEventListener\("offline", updateConnectionState\)/, "deve observar perda de conexão");
requirePattern(/showServiceWorkerUpdate\(worker\)/, "deve exibir atualização disponível");
requirePattern(/window\.addEventListener\("appinstalled"/, "deve tratar instalação concluída");
requirePattern(/function renderPWAInstallState\(state, overrideMessage = ""\)/, "estados PWA devem ter renderização centralizada");
requirePattern(/try \{[\s\S]*?promptEvent\.prompt\(\)[\s\S]*?catch \(error\)[\s\S]*?finally \{/, "instalação deve restaurar o botão mesmo em falhas");
requirePattern(/installLabel:\s*"Ver como instalar"/, "prompt consumido deve virar orientação, não ação inerte");
requirePattern(/Se essa opção não aparecer, abra a página no Safari/, "iOS deve orientar fallback para Safari sem bloquear outros navegadores");

// Modal com nome, descrição, foco contido e fechamento por Escape.
requirePattern(/id="modalDialog"[\s\S]*?role="dialog"[\s\S]*?aria-modal="true"[\s\S]*?aria-labelledby="modalTitle"[\s\S]*?aria-describedby="modalBody"/, "modal deve expor relações ARIA");
requirePattern(/function getModalFocusableElements\(\)/, "modal deve identificar controles focalizáveis");
requirePattern(/if \(e\.key === "Escape"\)/, "modal deve fechar com Escape");
requirePattern(/document\.body\.classList\.add\("modal-open"\)/, "modal deve controlar o fundo");

// Responsividade, foco e preferência de movimento.
requirePattern(/min-height:\s*100dvh/, "layout deve usar viewport dinâmica");
requirePattern(/min-height:\s*100vh;[\s\S]*?min-height:\s*100svh;[\s\S]*?min-height:\s*100dvh;/, "layout deve oferecer fallbacks de viewport móvel");
requirePattern(/env\(safe-area-inset-bottom\)/, "layout deve respeitar safe areas");
requirePattern(/-webkit-text-size-adjust:\s*100%/, "Safari não deve ampliar texto automaticamente");
requirePattern(/touch-action:\s*manipulation/, "controles móveis devem responder ao toque sem atraso");
requirePattern(/\.card-shell\s*\{[\s\S]*?width:\s*100%;[\s\S]*?max-width:\s*32\.8rem;/, "cartão não deve depender de min() para largura móvel");
requirePattern(/\.logo-top img\s*\{[\s\S]*?width:\s*10\.25rem;[\s\S]*?max-width:\s*56vw;/, "logo deve manter largura estável em flexbox móvel");
requirePattern(/:focus-visible/, "controles devem ter foco visível");
requirePattern(/@media \(max-width:\s*23\.75rem\)/, "deve haver ajuste explícito para 380px ou menos");
requirePattern(/:root\[data-theme="dark"\]/, "deve oferecer dark mode selecionável");
requirePattern(/window\.matchMedia\("\(prefers-color-scheme: dark\)"\)/, "primeiro acesso deve respeitar o tema do sistema");
requirePattern(/const THEME_STORAGE_KEY = "davez_theme";/, "preferência de tema deve usar chave estável");
requirePattern(/function applyTheme\(theme, persist = false\)/, "mudança de tema deve ter aplicação centralizada");
requirePattern(/localStorage\.setItem\(THEME_STORAGE_KEY, normalizedTheme\)/, "tema escolhido deve persistir no navegador");
requirePattern(/function readStoredTheme\(\)/, "preferência persistida deve ser validada antes do uso");
requirePattern(/window\.addEventListener\("storage"/, "tema deve ser sincronizado entre abas");
requirePattern(/function observeMediaQuery\(mediaQuery, listener\)/, "Safari antigo deve ter compatibilidade com MediaQueryList");
requirePattern(/mediaQuery\.addListener\(listener\)/, "fallback legado do Safari deve ser preservado");
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
  "access-header",
  "header-actions",
  "theme-control",
  "theme-option",
  "logo-top",
  "admin-access",
  "admin-access-icon",
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
  "pwa-visual",
  "pwa-copy",
  "pwa-kicker",
  "pwa-actions",
  "pwa-title",
  "pwa-text",
  "pwa-btn",
  "pwa-btn-icon",
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
  'const CHECKIN_URL = "checkin.php?v=4";',
  'const DAVEZ_ENTER_URL = "DaVez/entrar.php?v=2";',
  'const DAVEZ_LIST_URL = "DaVez/listar.php?v=2";',
  'const RECOVER_URL = "recover.php?v=1";',
  'const SESSION_INFO_URL = "session_info.php?v=2";',
]) {
  assert.ok(html.includes(endpoint), `endpoint preservado: ${endpoint}`);
}

for (const id of [
  "nome",
  "accessCode",
  "btn",
  "btnRecover",
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
const scriptBlocks = [...html.matchAll(/<script>([\s\S]*?)<\/script>/g)]
  .map(match => match[1]);
assert.ok(scriptBlocks.length >= 2, "scripts de tema e aplicação devem existir");
new vm.Script(scriptBlocks.join("\n"), { filename: "index.html:inline-scripts.js" });

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
assert.equal(manifest.orientation, "any");

for (const requiredSize of ["192x192", "512x512"]) {
  const icon = manifest.icons.find(candidate => candidate.sizes === requiredSize);
  assert.ok(icon, `manifesto deve oferecer ícone ${requiredSize}`);
  assert.match(icon.purpose, /\bany\b/, `${requiredSize} deve funcionar como ícone padrão`);
  assert.match(icon.purpose, /\bmaskable\b/, `${requiredSize} deve funcionar como ícone adaptável no Android`);
}

for (const icon of manifest.icons) {
  const dimensions = pngDimensions(path.join(projectRoot, icon.src.replace(/^\//, "")));
  assert.equal(icon.sizes, `${dimensions.width}x${dimensions.height}`, `${icon.src} deve declarar sua dimensão real`);
}

console.log("public_interface_accessibility_test: OK");

assert.match(html, /<body class="public-app">/);
assert.match(html, /assets\/css\/davez-tech-rc2\.css/);
