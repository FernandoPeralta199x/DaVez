<?php
include_once __DIR__ . "/../config.php";
require_admin();
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

$id = intval($_POST['id'] ?? 0);
if ($id <= 0) {
  http_response_code(400);
  echo json_encode(["ok"=>false, "err"=>"id obrigatório"], JSON_UNESCAPED_UNICODE);
  exit;
}

$tb = $conn->query("SHOW TABLES LIKE 'fila_da_vez'");
if (!$tb || $tb->num_rows === 0) {
  http_response_code(500);
  echo json_encode(["ok"=>false, "err"=>"Tabela fila_da_vez não existe. Rode o SQL de instalação."], JSON_UNESCAPED_UNICODE);
  exit;
}

$dia = get_operational_date();

$stmt = $conn->prepare("
  UPDATE fila_da_vez
  SET status='em_entrega', last_action_at=NOW()
  WHERE id=? AND dia=? AND status='na_fila'
  LIMIT 1
");

if (!$stmt) {
  http_response_code(500);
  echo json_encode([
    "ok"=>false,
    "err"=>"Erro no prepare do sair.php",
    "sql_error"=>$conn->error
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

$stmt->bind_param("is", $id, $dia);
$stmt->execute();

if ($stmt->affected_rows <= 0){
  http_response_code(404);
  echo json_encode([
    "ok"=>false,
    "err"=>"Nada atualizado (não está na fila ou não pertence ao ciclo operacional atual)"
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

echo json_encode([
  "ok"=>true,
  "dia"=>$dia
], JSON_UNESCAPED_UNICODE);