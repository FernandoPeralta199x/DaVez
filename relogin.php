<?php
// Re-login do dia (para voltar ao modo "aguardando" usando nome + token)
// Regra: o "login" existe se houver check-in HOJE com esse nome.
// Ao limpar lista no admin, os checkins do dia são apagados, logo os "logins" somem também.

require_once __DIR__ . '/src/Security/Bootstrap.php';
require_once __DIR__ . '/src/Domain/OperationalCycle.php';
require_once __DIR__ . '/src/Domain/OperationalContext.php';
davez_install_safe_exception_handler();
davez_require_http_method('POST');
davez_require_public_request_context();

try {
  davez_assert_allowed_input_keys($_POST, ['nome', 'token']);
  $reloginRate = davez_rate_limit_consume(
    'public-relogin',
    davez_rate_limit_request_subject(),
    12,
    60
  );
} catch (InvalidArgumentException $exception) {
  davez_send_error('invalid_request', 'Dados de acesso inválidos.', 400);
} catch (RuntimeException $exception) {
  davez_send_error(
    'security_control_unavailable',
    'Serviço temporariamente indisponível.',
    503
  );
}

if (!$reloginRate['allowed']) {
  header('Retry-After: ' . $reloginRate['retry_after']);
  davez_send_error(
    'rate_limit_exceeded',
    'Muitas tentativas. Aguarde e tente novamente.',
    429
  );
}

include "config.php";
include "log.php";

header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 0);

function json_out($arr){
  davez_send_json($arr, http_response_code() ?: 200);
}

@date_default_timezone_set('America/Sao_Paulo');
$conn->query("SET time_zone = '-03:00'");
$operationalContext = new \DaVez\Domain\OperationalContext(
  new \DaVez\Domain\OperationalCycle()
);
$operationalStart = $operationalContext->startSql();
$operationalEnd = $operationalContext->endSql();

log_event("RELOGIN_START");

try {
  $nome = davez_input_string($_POST, 'nome', 2, 80);
  $token = davez_input_string($_POST, 'token', 1, 32);
} catch (InvalidArgumentException $exception) {
  json_out(["ok"=>false, "msg"=>"Informe nome e token"]);
}

// lê settings (mesma base do checkin.php)
$sRes = $conn->query("SELECT * FROM settings WHERE id=1");
if (!$sRes) {
  log_event("RELOGIN_ERRO_SETTINGS_QUERY");
  http_response_code(500);
  json_out(["ok"=>false, "msg"=>"Erro ao ler configurações"]);
}
$s = $sRes->fetch_assoc();
if (!$s) {
  log_event("RELOGIN_ERRO_SETTINGS_INVALIDO");
  http_response_code(500);
  json_out(["ok"=>false, "msg"=>"Configurações inválidas"]);
}

// ✅ RELOGIN não depende de chamada aberta. Mas token continua obrigatório.
if ($token !== trim($s['token'] ?? '')) {
  http_response_code(403);
  json_out(["ok"=>false, "msg"=>"Token inválido"]);
}

// Procura check-in do ciclo atual por nome.
$stmt = $conn->prepare(
  "SELECT nome, ordem
     FROM checkins
    WHERE LOWER(TRIM(nome)) = LOWER(TRIM(?))
      AND data_hora >= ?
      AND data_hora < ?
    ORDER BY ordem ASC
    LIMIT 1"
);

if (!$stmt) {
  log_event("RELOGIN_ERRO_PREP");
  http_response_code(500);
  json_out(["ok"=>false, "msg"=>"Erro interno (prep)"]);
}

$stmt->bind_param("sss", $nome, $operationalStart, $operationalEnd);
if (!$stmt->execute()) {
  log_event("RELOGIN_ERRO_EXEC");
  http_response_code(500);
  json_out(["ok"=>false, "msg"=>"Erro interno (exec)"]);
}

$res = $stmt->get_result();
$row = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$row) {
  http_response_code(404);
  json_out(["ok"=>false, "msg"=>"Nome não encontrado no ciclo operacional atual"]);
}

$pos = intval($row['ordem'] ?? 0);
log_event("RELOGIN_OK", ["pos" => $pos]);

json_out(["ok"=>true, "nome"=>$row['nome'], "pos"=>$pos]);
