const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

const adminPath = path.resolve(__dirname, "..", "..", "admin.php");
const source = fs.readFileSync(adminPath, "utf8");
const inlineScripts = [...source.matchAll(/<script>([\s\S]*?)<\/script>/gi)];

if (inlineScripts.length === 0) {
  process.stderr.write("Nenhum script inline foi encontrado em admin.php.\n");
  process.exit(1);
}

for (const [index, match] of inlineScripts.entries()) {
  const script = match[1].replace(
    /<\?=\s*json_encode\([\s\S]*?\)\s*\?>/g,
    '"synthetic-csrf-token"'
  );

  try {
    new vm.Script(script, {
      filename: `admin-inline-${index + 1}.js`,
    });
  } catch (error) {
    process.stderr.write(
      `JavaScript inválido em admin.php: ${error.message}\n`
    );
    process.exit(1);
  }
}

process.stdout.write("admin_script_syntax: OK\n");
