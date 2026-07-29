<?php
// Re-login do dia (para voltar ao modo "aguardando" usando nome + token)
// Regra: o "login" existe se houver check-in HOJE com esse nome.
// Ao limpar lista no admin, os checkins do dia são apagados, logo os "logins" somem também.

include "config.php";
include "log.php";

header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 0);

function json_out($arr){
  echo json_encode($arr, JSON_UNESCAPED_UNICODE);
  exit;
}

// ✅ garante fuso do sistema (CURDATE() correto)
@date_default_timezone_set('America/Sao_Paulo');
$conn->query("SET time_zone = '-03:00'");

log_event("RELOGIN_START", ["post" => $_POST]);

$nome  = trim($_POST['nome'] ?? '');
$token = trim($_POST['token'] ?? '');

if ($nome === '' || $token === '') {
  json_out(["ok"=>false, "msg"=>"Informe nome e token"]);
}

// lê settings (mesma base do checkin.php)
$sRes = $conn->query("SELECT * FROM settings WHERE id=1");
if (!$sRes) {
  log_event("RELOGIN_ERRO_SETTINGS_QUERY", ["mysql_error" => $conn->error]);
  http_response_code(500);
  json_out(["ok"=>false, "msg"=>"Erro ao ler configurações"]);
}
$s = $sRes->fetch_assoc();
if (!$s) {
  log_event("RELOGIN_ERRO_SETTINGS_INVALIDO", ["settings" => $s]);
  http_response_code(500);
  json_out(["ok"=>false, "msg"=>"Configurações inválidas"]);
}

// ✅ RELOGIN não depende de chamada aberta. Mas token continua obrigatório.
if ($token !== trim($s['token'] ?? '')) {
  http_response_code(403);
  json_out(["ok"=>false, "msg"=>"Token inválido"]);
}

// procura check-in de hoje por nome (tolerante: TRIM + case-insensitive)
$stmt = $conn->prepare(
  "SELECT nome, ordem
     FROM checkins
    WHERE LOWER(TRIM(nome)) = LOWER(TRIM(?))
      AND data_hora >= CURDATE()
      AND data_hora < (CURDATE() + INTERVAL 1 DAY)
    ORDER BY ordem ASC
    LIMIT 1"
);

if (!$stmt) {
  log_event("RELOGIN_ERRO_PREP", ["mysql_error" => $conn->error]);
  http_response_code(500);
  json_out(["ok"=>false, "msg"=>"Erro interno (prep)"]);
}

$stmt->bind_param("s", $nome);
if (!$stmt->execute()) {
  log_event("RELOGIN_ERRO_EXEC", ["mysql_error" => $stmt->error]);
  http_response_code(500);
  json_out(["ok"=>false, "msg"=>"Erro interno (exec)"]);
}

$res = $stmt->get_result();
$row = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$row) {
  http_response_code(404);
  json_out(["ok"=>false, "msg"=>"Nome não encontrado na lista de hoje"]);
}

$pos = intval($row['ordem'] ?? 0);
log_event("RELOGIN_OK", ["nome" => $row['nome'], "pos" => $pos]);

json_out(["ok"=>true, "nome"=>$row['nome'], "pos"=>$pos]);