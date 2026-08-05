const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");

const root = path.resolve(__dirname, "..", "..");
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

for (const file of ["ranking_pdf.php", "reports_pdf.php", "report_pdf.php"]) {
  assert.ok(fs.existsSync(path.join(root, file)), `${file} deve existir.`);
  const source = read(file);
  assert.match(source, /davez_require_http_method\('GET'\)/, `${file}: somente GET.`);
  assert.match(source, /davez_require_admin\(\)/, `${file}: autenticação administrativa.`);
  assert.match(source, /davez_rate_limit_consume\(/, `${file}: rate limit.`);
  assert.match(source, /Content-Type: application\/pdf/, `${file}: tipo PDF.`);
  assert.match(source, /Cache-Control: private, no-store/, `${file}: sem cache compartilhado.`);
  assert.match(source, /X-Content-Type-Options: nosniff/, `${file}: nosniff.`);
  assert.match(source, /Cross-Origin-Resource-Policy: same-origin/, `${file}: isolamento de recurso.`);
  assert.doesNotMatch(source, /readfile\s*\(\s*\$_(?:GET|POST)/, `${file}: caminho não pode vir do cliente.`);
}

const ranking = read("ranking_pdf.php");
assert.match(ranking, /davez_assert_allowed_input_keys\([\s\S]*?'periodo'[\s\S]*?'date_from'[\s\S]*?'date_to'/);
assert.match(ranking, /customBounds\([\s\S]*?366/);
assert.match(ranking, /RankingQuery/);
assert.match(ranking, /fetchPage\([\s\S]*?500/);

const reports = read("reports_pdf.php");
assert.match(reports, /davez_assert_allowed_input_keys\(\$_GET, \['date_from', 'date_to'\]\)/);
assert.match(reports, /ReportListQuery/);
assert.match(reports, /fetchForExport\([\s\S]*?1000/);
assert.doesNotMatch(reports, /SELECT\s+[\s\S]*?FROM\s+reports/i, "SQL deve ficar centralizado em ReportListQuery.");

const reportQuery = read(path.join("src", "Application", "Reports", "ReportListQuery.php"));
assert.match(reportQuery, /LIMIT \? OFFSET \?/);
assert.match(reportQuery, /bind_param\([\s\S]*?'ssssii'/);
assert.match(reportQuery, /ORDER BY created_at DESC, id DESC/);
assert.doesNotMatch(reportQuery, /DATE\s*\(\s*periodo_inicio\s*\)/i, "Filtro deve preservar uso de índice.");

const individual = read("report_pdf.php");
assert.match(individual, /davez_input_integer\(/);
assert.match(individual, /WHERE id=\?/);

process.stdout.write("pdf_endpoints_policy: OK\n");
