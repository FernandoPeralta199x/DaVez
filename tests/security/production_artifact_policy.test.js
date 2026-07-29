"use strict";

const assert = require("node:assert/strict");
const { existsSync } = require("node:fs");
const { resolve } = require("node:path");

const projectRoot = resolve(__dirname, "..", "..");
const forbiddenPublicEndpoints = [
  "postteste.php",
  "teste_admin.php",
  "testedb.php",
];

for (const endpoint of forbiddenPublicEndpoints) {
  assert.equal(
    existsSync(resolve(projectRoot, endpoint)),
    false,
    `${endpoint} não pode existir no artefato público`
  );
}

console.log("production_artifact_policy: OK");
