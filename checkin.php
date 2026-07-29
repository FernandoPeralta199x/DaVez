<?php
require_once __DIR__ . '/src/Security/Bootstrap.php';
require_once __DIR__ . '/src/Domain/OperationalCycle.php';
require_once __DIR__ . '/src/Domain/OperationalContext.php';
require_once __DIR__ . '/src/Domain/Geofence.php';
require_once __DIR__ . '/src/Database/bootstrap.php';
davez_install_safe_exception_handler();

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

davez_require_http_method('POST');
davez_require_public_request_context();

try {
  davez_assert_allowed_input_keys(
    $_POST,
    ['nome', 'token', 'lat', 'lng', 'client_id']
  );
  $checkinRate = davez_rate_limit_consume(
    'public-checkin',
    davez_rate_limit_request_subject(),
    10,
    60
  );
} catch (InvalidArgumentException $exception) {
  davez_send_error(
    'invalid_request',
    'Dados de check-in inválidos.',
    400
  );
} catch (RuntimeException $exception) {
  davez_send_error(
    'security_control_unavailable',
    'Serviço temporariamente indisponível.',
    503
  );
}

if (!$checkinRate['allowed']) {
  header('Retry-After: ' . $checkinRate['retry_after']);
  davez_send_error(
    'rate_limit_exceeded',
    'Muitas tentativas. Aguarde e tente novamente.',
    429
  );
}

include "config.php";
include "log.php";

@date_default_timezone_set('America/Sao_Paulo');
$conn->query("SET time_zone = '-03:00'");
$operationalContext = new \DaVez\Domain\OperationalContext(
  new \DaVez\Domain\OperationalCycle()
);
$operationalStart = $operationalContext->startSql();
$operationalEnd = $operationalContext->endSql();
$operationalDate = $operationalContext->date();

header('Content-Type: text/plain; charset=utf-8');

error_reporting(E_ALL);
ini_set('display_errors', 0);

register_shutdown_function(function () {
  $err = error_get_last();
  if ($err) {
    include_once __DIR__ . "/log.php";
    log_event("FATAL_SHUTDOWN", [
      "error_type" => $err["type"] ?? null,
      "error_line" => $err["line"] ?? null,
    ]);
  }
});

log_event("CHECKIN_START");

try {
  $nome = davez_input_string($_POST, 'nome', 2, 80);
  $token = davez_input_string($_POST, 'token', 1, 32);
  $lat = davez_input_float($_POST, 'lat', -90, 90);
  $lng = davez_input_float($_POST, 'lng', -180, 180);
} catch (InvalidArgumentException $exception) {
  log_event("ERRO_NOME_VAZIO");
  davez_send_error(
    'invalid_checkin_data',
    'Confira nome, token e localização.',
    400
  );
}

$clientId = is_string($_POST['client_id'] ?? null)
  ? trim($_POST['client_id'])
  : (is_string($_COOKIE['cid'] ?? null) ? $_COOKIE['cid'] : '');

$sRes = $conn->query("SELECT * FROM settings WHERE id=1");
if (!$sRes) {
  log_event("ERRO_SETTINGS_QUERY");
  http_response_code(500);
  die("Erro ao ler configurações");
}

$s = $sRes->fetch_assoc();
if (!$s || !isset($s['chamada_aberta'])) {
  log_event("ERRO_SETTINGS_INVALIDO");
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
  log_event("ERRO_TOKEN_INVALIDO");
  http_response_code(403);
  die("Token inválido");
}

log_event("TOKEN_OK");

if (!preg_match('/^[a-f0-9]{32}$/', $clientId)) {
  $clientId = \DaVez\Domain\LegacyIdentity::clientId();
  log_event("CID_GERADO");
} else {
  log_event("CID_OK");
}

setcookie('cid', $clientId, [
  'expires' => time() + 60 * 60 * 24 * 30,
  'path' => '/',
  'secure' => davez_is_https_request(),
  'httponly' => true,
  'samesite' => 'Lax'
]);

if ($lat == 0 || $lng == 0) {
  log_event("ERRO_LOCALIZACAO_ZERO");
  http_response_code(400);
  die("Ative a localização e tente novamente");
}

$latBase = floatval($s['lat_base']);
$lngBase = floatval($s['lng_base']);
$raio    = floatval($s['raio']);

try {
  $geofence = \DaVez\Domain\Geofence::evaluate(
    $latBase,
    $lngBase,
    $raio,
    $lat,
    $lng
  );
} catch (InvalidArgumentException $exception) {
  log_event("ERRO_GEOFENCE_CONFIG");
  davez_send_error(
    'geofence_unavailable',
    'Validação de localização temporariamente indisponível.',
    503
  );
}

log_event("DIST_OK", [
  "distancia_m" => $geofence['distance_m'],
  "raio_m" => $raio
]);

if (!$geofence['within']) {
  log_event("ERRO_FORA_DO_RAIO", [
    "distancia_m" => $geofence['distance_m'],
    "raio_m" => $raio
  ]);
  http_response_code(403);
  die("Você não está no local");
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

$allocator = davez_atomic_order_allocator($conn);

try {
  $next = $allocator->allocateAndPersist(
    'checkins:' . $operationalDate,
    static function () use (
      $conn,
      $clientId,
      $nome,
      $operationalStart,
      $operationalEnd
    ): int {
      $byClient = $conn->prepare(
        "SELECT id
         FROM checkins
         WHERE client_id=?
           AND data_hora >= ?
           AND data_hora < ?
         LIMIT 1"
      );

      if (!$byClient) {
        throw new RuntimeException('Falha ao preparar duplicidade por cliente.');
      }

      $byClient->bind_param(
        "sss",
        $clientId,
        $operationalStart,
        $operationalEnd
      );

      if (!$byClient->execute()) {
        $byClient->close();
        throw new RuntimeException('Falha ao validar duplicidade por cliente.');
      }

      $byClient->store_result();
      $clientExists = $byClient->num_rows > 0;
      $byClient->close();

      if ($clientExists) {
        throw new DomainException('duplicate_client');
      }

      $byName = $conn->prepare(
        "SELECT id
         FROM checkins
         WHERE LOWER(TRIM(nome)) = LOWER(TRIM(?))
           AND data_hora >= ?
           AND data_hora < ?
         LIMIT 1"
      );

      if (!$byName) {
        throw new RuntimeException('Falha ao preparar duplicidade por nome.');
      }

      $byName->bind_param(
        "sss",
        $nome,
        $operationalStart,
        $operationalEnd
      );

      if (!$byName->execute()) {
        $byName->close();
        throw new RuntimeException('Falha ao validar duplicidade por nome.');
      }

      $byName->store_result();
      $nameExists = $byName->num_rows > 0;
      $byName->close();

      if ($nameExists) {
        throw new DomainException('duplicate_name');
      }

      $maximum = $conn->prepare(
        "SELECT COALESCE(MAX(ordem), 0)
         FROM checkins
         WHERE data_hora >= ?
           AND data_hora < ?"
      );

      if (!$maximum) {
        throw new RuntimeException('Falha ao preparar próxima ordem.');
      }

      $maximum->bind_param("ss", $operationalStart, $operationalEnd);

      if (!$maximum->execute()) {
        $maximum->close();
        throw new RuntimeException('Falha ao consultar próxima ordem.');
      }

      $maximumOrder = 0;
      $maximum->bind_result($maximumOrder);
      $maximum->fetch();
      $maximum->close();

      return (int) $maximumOrder;
    },
    static function (int $order) use (
      $conn,
      $nome,
      $clientId,
      $ip,
      $ua
    ): void {
      $statement = $conn->prepare(
        "INSERT INTO checkins
           (nome, client_id, ip, user_agent, data_hora, ordem)
         VALUES (?, ?, ?, ?, NOW(), ?)"
      );

      if (!$statement) {
        throw new RuntimeException('Falha ao preparar inserção.');
      }

      $statement->bind_param(
        "ssssi",
        $nome,
        $clientId,
        $ip,
        $ua,
        $order
      );

      if (!$statement->execute()) {
        $statement->close();
        throw new RuntimeException('Falha ao inserir check-in.');
      }

      $statement->close();
    }
  );
} catch (DomainException $exception) {
  if ($exception->getMessage() === 'duplicate_client') {
    log_event("ERRO_DUPLICADO");
    davez_send_error(
      'duplicate_checkin',
      'Check-in já realizado no ciclo operacional atual.',
      409
    );
  }

  log_event("ERRO_DUPLICADO_NOME");
  davez_send_error(
    'duplicate_name',
    'Este nome já realizou check-in no ciclo operacional atual.',
    409
  );
} catch (\DaVez\Database\LockUnavailable $exception) {
  log_event("ORDEM_LOCK_FALHOU");
  header('Retry-After: 2');
  davez_send_error(
    'queue_busy',
    'Fila ocupada. Aguarde e tente novamente.',
    503
  );
} catch (Throwable $exception) {
  log_event("ERRO_TX_ORDEM_INSERT");
  davez_send_error(
    'checkin_failed',
    'Não foi possível registrar o check-in.',
    500
  );
}

log_event("CHECKIN_OK", ["ordem" => $next]);

echo "Check-in confirmado com sucesso! Sua posição na lista: " . intval($next) . "º";
