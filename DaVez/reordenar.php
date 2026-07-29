<?php
session_start();
include_once __DIR__ . "/../config.php";

require_admin();

header('Content-Type: application/json; charset=utf-8');

date_default_timezone_set('America/Sao_Paulo');

function json_out($data, $code = 200){
  http_response_code($code);
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_out(['ok' => false, 'err' => 'Método inválido'], 405);
}

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

if (!is_array($data)) {
  json_out(['ok' => false, 'err' => 'Payload inválido'], 400);
}

$ordem = $data['ordem'] ?? null;
if (!is_array($ordem) || empty($ordem)) {
  json_out(['ok' => false, 'err' => 'Lista de ordem inválida'], 400);
}

$tb = $conn->query("SHOW TABLES LIKE 'fila_da_vez'");
if (!$tb || $tb->num_rows === 0) {
  json_out(['ok' => false, 'err' => 'Tabela fila_da_vez não existe. Rode o SQL de instalação.'], 500);
}

$dia = get_operational_date();

$conn->begin_transaction();

try {
  $stmt = $conn->prepare("
    UPDATE fila_da_vez
    SET ordem=?
    WHERE id=?
      AND dia=?
      AND status='na_fila'
  ");

  if (!$stmt) {
    throw new Exception("Prepare falhou: " . $conn->error);
  }

  $pos = 1;
  foreach ($ordem as $id) {
    $id = intval($id);
    if ($id <= 0) continue;

    $stmt->bind_param("iis", $pos, $id, $dia);
    $stmt->execute();
    $pos++;
  }

  $conn->commit();
  json_out([
    'ok' => true,
    'dia' => $dia
  ]);

} catch (Exception $e) {
  $conn->rollback();
  json_out([
    'ok' => false,
    'err' => 'Falha ao atualizar ordem da fila',
    'debug' => $e->getMessage()
  ], 500);
}