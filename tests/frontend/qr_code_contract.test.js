const assert = require("node:assert/strict");
const crypto = require("node:crypto");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

const root = path.resolve(__dirname, "..", "..");
const read = (relativePath) =>
  fs.readFileSync(path.join(root, relativePath), "utf8");

const vendorPath = path.join(
  root,
  "js",
  "qrcode-generator-1.4.4.min.js"
);
const vendor = fs.readFileSync(vendorPath, "utf8");
const vendorBody = vendor.split(/\r?\n/).slice(1).join("\n").trimEnd();
const vendorLicense = read("js/qrcode-generator.LICENSE.txt");
const admin = read("admin.php");
const publicInterface = read("index.html");
const releaseScript = read("scripts/build-release.ps1");

assert.match(vendor, /qrcode-generator v1\.4\.4/);
assert.equal(
  crypto.createHash("sha256").update(vendorBody).digest("hex"),
  "164fc2c1c9eaf0a03aa8dfdb855e82e41a5c6922fbad8bb3238116207e26bef7",
  "O gerador local deve permanecer idêntico ao artefato 1.4.4 fixado."
);
assert.match(vendorLicense, /MIT License/);
assert.match(vendorLicense, /Copyright \(c\) 2009 Kazuhiko Arase/);

for (const forbiddenNetworkPrimitive of [
  /\bfetch\s*\(/,
  /\bXMLHttpRequest\b/,
  /\bWebSocket\b/,
  /\bsendBeacon\b/,
  /\bEventSource\b/,
  /\bimportScripts\b/,
]) {
  assert.doesNotMatch(
    vendorBody,
    forbiddenNetworkPrimitive,
    "O gerador de QR não pode realizar comunicação de rede."
  );
}

const moduleShim = { exports: {} };
vm.runInNewContext(vendorBody, {
  module: moduleShim,
  exports: moduleShim.exports,
});
const createQr = moduleShim.exports;
assert.equal(typeof createQr, "function");

for (const payload of [
  "https://davez.example/",
  "https://davez.example/#access_code=1166-aabb",
]) {
  const qr = createQr(0, "M");
  qr.addData(payload);
  qr.make();

  const moduleCount = qr.getModuleCount();
  assert.ok(moduleCount >= 21 && moduleCount <= 177);
  assert.equal(qr.isDark(0, 0), true);
  assert.equal(qr.isDark(6, 6), true);
  assert.match(qr.createDataURL(2, 8), /^data:image\/gif;base64,/);
}

assert.match(
  admin,
  /<script src="js\/qrcode-generator-1\.4\.4\.min\.js"><\/script>/
);
assert.doesNotMatch(
  admin,
  /<script[^>]+src="https?:\/\/[^"]*(?:qrcode|qr-code)[^"]*"/i,
  "O gerador de QR não pode ser carregado por CDN."
);
assert.match(
  admin,
  /'ticket_status' => \['acao', 'access_code', '_csrf'\]/
);
assert.match(
  admin,
  /davez_public_ticket_hash\(\$accessCode\)[\s\S]*?findDailyCodeStatus|findDailyCodeStatus\([\s\S]*?davez_public_ticket_hash\(\$accessCode\)/
);
assert.match(admin, /publicUrl\.hash = new URLSearchParams/);
assert.doesNotMatch(
  admin,
  /publicUrl\.searchParams\.set\(['"]access_code/,
  "O QR individual não pode enviar o código em query string."
);
assert.match(
  publicInterface,
  /window\.history\.replaceState\(null, document\.title, cleanAddress \|\| "\/"\)/
);
assert.match(releaseScript, /'js'/);

process.stdout.write("qr_code_contract: OK\n");
