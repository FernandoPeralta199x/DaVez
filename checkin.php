<?php
// ===== Warm-up para InfinityFree (GET) =====
// Quando o provedor aplica challenge, ele costuma redirecionar para GET.
// Então: se não for POST, devolve uma página que volta pro index.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  header('Content-Type: text/html; charset=utf-8');
  $back = 'index.html?warm=1';
  echo "<!doctype html><html><head><meta charset='utf-8'>
  <meta http-equiv='refresh' content='0;url={$back}'>
  <script>location.replace('{$back}');</script>
  </head><body>OK</body></html>";
  exit;
}

include "config.php";
include "log.php";

@date_default_timezone_set('America/Sao_Paulo');
$conn->query("SET time_zone = '-03:00'");

header('Content-Type: text/plain; charset=utf-8');

error_reporting(E_ALL);
ini_set('display_errors', 0);

register_shutdown_function(function () {
  $err = error_get_last();
  if ($err) {
    include_once __DIR__ . "/log.php";
    log_event("FATAL_SHUTDOWN", $err);
  }
});

log_event("CHECKIN_START", ["post" => $_POST]);

$nome     = trim($_POST['nome'] ?? '');
$token    = trim($_POST['token'] ?? '');
$lat      = floatval($_POST['lat'] ?? 0);
$lng      = floatval($_POST['lng'] ?? 0);
$clientId = $_POST['client_id'] ?? ($_COOKIE['cid'] ?? '');

if ($nome === '') {
  log_event("ERRO_NOME_VAZIO");
  http_response_code(400);
  die("Informe seu nome");
}

$sRes = $conn->query("SELECT * FROM settings WHERE id=1");
if (!$sRes) {
  log_event("ERRO_SETTINGS_QUERY", ["mysql_error" => $conn->error]);
  http_response_code(500);
  die("Erro ao ler configurações");
}

$s = $sRes->fetch_assoc();
if (!$s || !isset($s['chamada_aberta'])) {
  log_event("ERRO_SETTINGS_INVALIDO", ["settings" => $s]);
  http_response_code(500);
  die("Configurações inválidas");
}

log_event("SETTINGS_OK", [
  "chamada_aberta" => $s['chamada_aberta'],
  "raio" => $s['raio'] ?? null,
]);

if (!$s['chamada_aberta']) {
  log_event("ERRO_CHAMADA_FECHADA");
  http_response_code(403);
  die("Chamada fechada");
}

if ($token === '') {
  log_event("ERRO_TOKEN_AUSENTE");
  http_response_code(400);
  die("Token não enviado");
}

if ($token !== $s['token']) {
  log_event("ERRO_TOKEN_INVALIDO", ["enviado" => $token, "esperado" => $s['token']]);
  http_response_code(403);
  die("Token inválido");
}

log_event("TOKEN_OK");

if (!preg_match('/^[a-f0-9]{32}$/', $clientId)) {
  $clientId = md5(uniqid('', true));
  log_event("CID_GERADO", ["client_id" => $clientId]);
} else {
  log_event("CID_OK", ["client_id" => $clientId]);
}

setcookie('cid', $clientId, [
  'expires' => time() + 60 * 60 * 24 * 30,
  'path' => '/',
  'secure' => isset($_SERVER['HTTPS']),
  'httponly' => true,
  'samesite' => 'Lax'
]);

if ($lat == 0 || $lng == 0) {
  log_event("ERRO_LOCALIZACAO_ZERO", ["lat" => $lat, "lng" => $lng]);
  http_response_code(400);
  die("Ative a localização e tente novamente");
}

$latBase = floatval($s['lat_base']);
$lngBase = floatval($s['lng_base']);
$raio    = floatval($s['raio']);

$dist = sqrt(
  pow($lat - $latBase, 2) +
  pow($lng - $lngBase, 2)
) * 111000;

log_event("DIST_OK", [
  "lat" => $lat,
  "lng" => $lng,
  "lat_base" => $latBase,
  "lng_base" => $lngBase,
  "distancia_m" => $dist,
  "raio_m" => $raio
]);

if ($dist > $raio) {
  log_event("ERRO_FORA_DO_RAIO", ["distancia_m" => $dist, "raio_m" => $raio]);
  http_response_code(403);
  die("Você não está no local");
}

/* ===== RANGE DO DIA (rápido e usa índice) ===== */
$ver = $conn->prepare(
  "SELECT id FROM checkins
   WHERE client_id=?
     AND data_hora >= CURDATE()
     AND data_hora < (CURDATE() + INTERVAL 1 DAY)
   LIMIT 1"
);

if (!$ver) {
  log_event("ERRO_PREP_DUPLICIDADE", ["mysql_error" => $conn->error]);
  http_response_code(500);
  die("Erro interno (validação)");
}

$ver->bind_param("s", $clientId);
if (!$ver->execute()) {
  log_event("ERRO_EXEC_DUPLICIDADE", ["mysql_error" => $ver->error]);
  http_response_code(500);
  die("Erro interno (validação)");
}
$ver->store_result();

if ($ver->num_rows > 0) {
  log_event("ERRO_DUPLICADO", ["client_id" => $clientId]);
  http_response_code(409);
  die("Check-in já realizado hoje");
}
/* ===== BLOQUEIO POR NOME (evita repetir nome em aba anônima) ===== */
$verNome = $conn->prepare(
  "SELECT id, ordem FROM checkins
   WHERE LOWER(TRIM(nome)) = LOWER(TRIM(?))
     AND data_hora >= CURDATE()
     AND data_hora < (CURDATE() + INTERVAL 1 DAY)
   LIMIT 1"
);

if (!$verNome) {
  log_event("ERRO_PREP_DUP_NOME", ["mysql_error" => $conn->error]);
  http_response_code(500);
  die("Erro interno (validação nome)");
}

$verNome->bind_param("s", $nome);

if (!$verNome->execute()) {
  log_event("ERRO_EXEC_DUP_NOME", ["mysql_error" => $verNome->error]);
  http_response_code(500);
  die("Erro interno (validação nome)");
}

$resNome = $verNome->get_result();
$ja = $resNome ? $resNome->fetch_assoc() : null;
$verNome->close();

if ($ja) {
  log_event("ERRO_DUPLICADO_NOME", ["nome" => $nome, "ordem" => $ja['ordem'] ?? null]);
  http_response_code(409);
  die("Este nome já realizou check-in hoje. Se for você, use o re-login com o mesmo nome e token.");
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

/*
  ===== ORDEM AUTOMÁTICA (CORRIGIDO) =====
  Objetivo:
  - gravar ordem já no INSERT (não deixar 9999)
  - evitar colisão com check-ins simultâneos
  Estratégia:
  - usar um lock global curto por dia (GET_LOCK)
  - dentro do lock: pegar MAX(ordem) do dia e inserir com ordem = max + 1
  - transação para garantir consistência
*/
$today = date('Y-m-d');
$lockName = "motoboys_ordem_" . $today; // nome do lock por dia
$gotLock = 0;

try {
  // começa transação
  if (!$conn->begin_transaction()) {
    log_event("ERRO_BEGIN_TX", ["mysql_error" => $conn->error]);
    throw new Exception("Erro interno (transação)");
  }

  // tenta pegar lock por até 3 segundos (não queremos travar o mundo)
  $lk = $conn->prepare("SELECT GET_LOCK(?, 3) AS l");
  if ($lk) {
    $lk->bind_param("s", $lockName);
    if ($lk->execute()) {
      $res = $lk->get_result();
      $row = $res ? $res->fetch_assoc() : null;
      $gotLock = intval($row['l'] ?? 0);
    }
    $lk->close();
  }

  if ($gotLock !== 1) {
    // fallback: segue sem lock (ainda funciona; risco mínimo de colisão em pico)
    log_event("ORDEM_LOCK_FALHOU", ["lock" => $lockName, "got" => $gotLock]);
  } else {
    log_event("ORDEM_LOCK_OK", ["lock" => $lockName]);
  }

  // pega a próxima ordem do dia (se não existir, começa em 1)
  $next = 1;
  $q = $conn->query(
    "SELECT COALESCE(MAX(ordem), 0) AS mx
     FROM checkins
     WHERE data_hora >= CURDATE()
       AND data_hora < (CURDATE() + INTERVAL 1 DAY)"
  );
  if ($q) {
    $mx = intval(($q->fetch_assoc()['mx'] ?? 0));
    $next = $mx + 1;
  } else {
    log_event("ERRO_MAX_ORDEM_QUERY", ["mysql_error" => $conn->error]);
    // mantém $next=1 como fallback
  }

  $stmt = $conn->prepare(
    "INSERT INTO checkins (nome, client_id, ip, user_agent, data_hora, ordem)
     VALUES (?, ?, ?, ?, NOW(), ?)"
  );

  if (!$stmt) {
    log_event("ERRO_PREP_INSERT", ["mysql_error" => $conn->error]);
    throw new Exception("Erro interno (inserção)");
  }

  $stmt->bind_param("ssssi", $nome, $clientId, $ip, $ua, $next);

  if (!$stmt->execute()) {
    log_event("ERRO_EXEC_INSERT", ["mysql_error" => $stmt->error]);
    $stmt->close();
    throw new Exception("Erro ao registrar check-in");
  }
  $stmt->close();

  // libera lock (se pegou)
  if ($gotLock === 1) {
    $ul = $conn->prepare("SELECT RELEASE_LOCK(?) AS r");
    if ($ul) {
      $ul->bind_param("s", $lockName);
      $ul->execute();
      $ul->close();
    }
  }

  // commit
  if (!$conn->commit()) {
    log_event("ERRO_COMMIT_TX", ["mysql_error" => $conn->error]);
    throw new Exception("Erro interno (commit)");
  }

} catch (Exception $e) {
  // rollback
  if ($conn && $conn->errno === 0) {
    // ok
  }
  if ($conn) {
    $conn->rollback();
  }

  // tenta liberar lock mesmo em erro
  if ($gotLock === 1) {
    $ul = $conn->prepare("SELECT RELEASE_LOCK(?) AS r");
    if ($ul) {
      $ul->bind_param("s", $lockName);
      $ul->execute();
      $ul->close();
    }
  }

  log_event("ERRO_TX_ORDEM_INSERT", ["msg" => $e->getMessage()]);
  http_response_code(500);
  die($e->getMessage());
}

log_event("CHECKIN_OK", ["nome" => $nome, "client_id" => $clientId, "ordem" => $next]);

echo "Check-in confirmado com sucesso! Sua posição na lista: " . intval($next) . "º";
