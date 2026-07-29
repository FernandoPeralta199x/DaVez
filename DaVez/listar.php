<?php
include_once __DIR__ . "/../config.php";
header('Content-Type: application/json; charset=utf-8');

date_default_timezone_set('America/Sao_Paulo');

function get_operational_date(?DateTime $ref = null){
  $tz = new DateTimeZone('America/Sao_Paulo');
  $now = $ref ? clone $ref : new DateTime('now', $tz);

  $start = clone $now;
  $start->setTime(6, 0, 0);

  if ((int)$now->format('H') < 6) {
    $start->modify('-1 day');
  }

  return $start->format('Y-m-d');
}

$tb = $conn->query("SHOW TABLES LIKE 'fila_da_vez'");
if (!$tb || $tb->num_rows === 0) {
  http_response_code(500);
  echo json_encode([
    "ok"=>false,
    "err"=>"Tabela fila_da_vez não existe. Rode o SQL de instalação."
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

// garante coluna ordem se a tabela já existia antes
$colCheck = $conn->query("SHOW COLUMNS FROM fila_da_vez LIKE 'ordem'");
if ($colCheck && $colCheck->num_rows === 0) {
  $conn->query("ALTER TABLE fila_da_vez ADD COLUMN ordem INT NOT NULL DEFAULT 0 AFTER entered_at");
  $conn->query("ALTER TABLE fila_da_vez ADD INDEX idx_dia_ordem (dia, ordem)");
}

$dia = get_operational_date();

$stmt = $conn->prepare("
  SELECT id, client_id, nome, entered_at, status, last_action_at, ordem
  FROM fila_da_vez
  WHERE dia=?
  ORDER BY
    CASE WHEN status='na_fila' THEN 0 ELSE 1 END,
    ordem ASC,
    entered_at ASC
");

if (!$stmt) {
  http_response_code(500);
  echo json_encode([
    "ok" => false,
    "err" => "Erro no prepare do listar.php",
    "sql_error" => $conn->error
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

$stmt->bind_param("s", $dia);
$stmt->execute();
$res = $stmt->get_result();

$fila = [];
$daVez = null;

while($r = $res->fetch_assoc()){
  $fila[] = $r;
}

foreach($fila as $r){
  if (($r['status'] ?? '') === 'na_fila') {
    $daVez = $r;
    break;
  }
}

echo json_encode([
  "ok" => true,
  "dia" => $dia,
  "da_vez" => $daVez,
  "fila" => $fila
], JSON_UNESCAPED_UNICODE);