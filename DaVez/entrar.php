<?php
require_once __DIR__ . '/../src/Security/Bootstrap.php';
require_once __DIR__ . '/../src/Domain/OperationalCycle.php';
require_once __DIR__ . '/../src/Domain/OperationalContext.php';
require_once __DIR__ . '/../src/Domain/Geofence.php';
require_once __DIR__ . '/../src/Database/bootstrap.php';
davez_install_safe_exception_handler();

// ===== Warm-up para InfinityFree (GET) =====
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  header('Content-Type: text/html; charset=utf-8');
  $back = '../index.html?warm=1';
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
  $enterRate = davez_rate_limit_consume(
    'public-enter-queue',
    davez_rate_limit_request_subject(),
    12,
    60
  );
} catch (InvalidArgumentException $exception) {
  davez_send_error('invalid_request', 'Dados de entrada inválidos.', 400);
} catch (RuntimeException $exception) {
  davez_send_error(
    'security_control_unavailable',
    'Serviço temporariamente indisponível.',
    503
  );
}

if (!$enterRate['allowed']) {
  header('Retry-After: ' . $enterRate['retry_after']);
  davez_send_error(
    'rate_limit_exceeded',
    'Muitas tentativas. Aguarde e tente novamente.',
    429
  );
}

include_once __DIR__ . "/../config.php";
include_once __DIR__ . "/../log.php";

header('Content-Type: text/plain; charset=utf-8');

error_reporting(E_ALL);
ini_set('display_errors', 0);
date_default_timezone_set('America/Sao_Paulo');
$operationalContext = new \DaVez\Domain\OperationalContext(
  new \DaVez\Domain\OperationalCycle()
);

register_shutdown_function(function () {
  $err = error_get_last();
  if ($err) {
    include_once __DIR__ . "/../log.php";
    log_event("DAVEZ_FATAL_SHUTDOWN", [
      "error_type" => $err["type"] ?? null,
      "error_line" => $err["line"] ?? null,
    ]);
  }
});

log_event("DAVEZ_ENTRAR_START");

try {
  $nome = davez_input_string($_POST, 'nome', 0, 80, false) ?? '';
  $token = davez_input_string($_POST, 'token', 1, 32);
  $lat = davez_input_float($_POST, 'lat', -90, 90);
  $lng = davez_input_float($_POST, 'lng', -180, 180);
} catch (InvalidArgumentException $exception) {
  davez_send_error('invalid_request', 'Dados de entrada inválidos.', 400);
}

$clientId = is_string($_POST['client_id'] ?? null)
  ? trim($_POST['client_id'])
  : (is_string($_COOKIE['cid'] ?? null) ? $_COOKIE['cid'] : '');

$opStartStr = $operationalContext->startSql();
$opEndStr = $operationalContext->endSql();
$dia = $operationalContext->date();

// ✅ Fallback: se não veio nome, tenta puxar do check-in do ciclo atual pelo client_id
if ($nome === '') {
  if (!empty($clientId)) {
    $stmtN = $conn->prepare("
      SELECT nome
      FROM checkins
      WHERE client_id=?
        AND data_hora >= ?
        AND data_hora < ?
      ORDER BY id DESC
      LIMIT 1
    ");
    if ($stmtN) {
      $stmtN->bind_param("sss", $clientId, $opStartStr, $opEndStr);
      $stmtN->execute();
      $rN = $stmtN->get_result();
      $rowN = $rN ? $rN->fetch_assoc() : null;
      $nome = trim($rowN['nome'] ?? '');
      $stmtN->close();
    }
  }

  if ($nome === '') {
    log_event("DAVEZ_ERRO_NOME_VAZIO");
    http_response_code(400);
    die("Informe seu nome");
  }
}

if ($token === '') {
  log_event("DAVEZ_ERRO_TOKEN_VAZIO");
  http_response_code(400);
  die("Informe o token atual");
}

// settings com rotação automática correta
try {
  $s = davez_settings_token_cycle($conn)->loadAndRotate(
    $operationalContext
  );
} catch (Exception $e) {
  log_event("DAVEZ_ERRO_SETTINGS");
  http_response_code(500);
  die("Erro ao ler settings");
}

$tokenAtual = trim($s['token'] ?? '');
$raio = intval($s['raio'] ?? 0);
$latBase = floatval($s['lat_base'] ?? 0);
$lngBase = floatval($s['lng_base'] ?? 0);

if ($tokenAtual === '' || $token !== $tokenAtual) {
  log_event("DAVEZ_ERRO_TOKEN_INVALIDO");
  http_response_code(401);
  die("Token inválido");
}

// valida latitude/longitude
if (!$lat || !$lng) {
  log_event("DAVEZ_ERRO_COORDS");
  http_response_code(400);
  die("Localização inválida");
}

// valida cid
if (!preg_match('/^[a-f0-9]{32}$/', $clientId)) {
  $clientId = \DaVez\Domain\LegacyIdentity::clientId();
  log_event("DAVEZ_CID_GERADO");
} else {
  log_event("DAVEZ_CID_OK");
}

setcookie('cid', $clientId, [
  'expires' => time() + 60*60*24*365,
  'path' => '/',
  'secure' => davez_is_https_request(),
  'httponly' => false,
  'samesite' => 'Lax'
]);

try {
  $geofence = \DaVez\Domain\Geofence::evaluate(
    $latBase,
    $lngBase,
    (float) $raio,
    $lat,
    $lng
  );
} catch (InvalidArgumentException $exception) {
  log_event("DAVEZ_GEOFENCE_CONFIG_INVALIDA");
  davez_send_error(
    'geofence_unavailable',
    'Validação de localização temporariamente indisponível.',
    503
  );
}

log_event("DAVEZ_DIST", [
  "dist_m" => $geofence['distance_m'],
  "raio" => $raio
]);

if (!$geofence['within']) {
  http_response_code(403);
  die("Você está fora do raio permitido");
}

$nomeCheck = trim(mb_strtolower($nome));

$allocator = davez_atomic_order_allocator($conn);

try {
  $nextOrdem = $allocator->allocateAndPersist(
    'fila_da_vez:' . $dia,
    static function () use ($conn, $dia, $nomeCheck): int {
      $duplicate = $conn->prepare(
        "SELECT id
         FROM fila_da_vez
         WHERE dia=?
           AND LOWER(TRIM(nome))=?
           AND status='na_fila'
         LIMIT 1"
      );

      if (!$duplicate) {
        throw new RuntimeException('Fila indisponível para validação.');
      }

      $duplicate->bind_param("ss", $dia, $nomeCheck);

      if (!$duplicate->execute()) {
        $duplicate->close();
        throw new RuntimeException('Fila indisponível para validação.');
      }

      $duplicate->store_result();
      $nameExists = $duplicate->num_rows > 0;
      $duplicate->close();

      if ($nameExists) {
        throw new DomainException('duplicate_name');
      }

      $maximum = $conn->prepare(
        "SELECT COALESCE(MAX(ordem), 0)
         FROM fila_da_vez
         WHERE dia=?"
      );

      if (!$maximum) {
        throw new RuntimeException('Fila indisponível para ordenação.');
      }

      $maximum->bind_param("s", $dia);

      if (!$maximum->execute()) {
        $maximum->close();
        throw new RuntimeException('Fila indisponível para ordenação.');
      }

      $maximumOrder = 0;
      $maximum->bind_result($maximumOrder);
      $maximum->fetch();
      $maximum->close();

      return (int) $maximumOrder;
    },
    static function (int $order) use (
      $conn,
      $dia,
      $clientId,
      $nome
    ): void {
      $statement = $conn->prepare(
        "INSERT INTO fila_da_vez
           (dia, client_id, nome, entered_at, ordem, status, last_action_at)
         VALUES (?, ?, ?, NOW(), ?, 'na_fila', NOW())
         ON DUPLICATE KEY UPDATE
           nome=VALUES(nome),
           entered_at=NOW(),
           ordem=VALUES(ordem),
           status='na_fila',
           last_action_at=NOW()"
      );

      if (!$statement) {
        throw new RuntimeException('Fila indisponível para inserção.');
      }

      $statement->bind_param("sssi", $dia, $clientId, $nome, $order);

      if (!$statement->execute() || $statement->affected_rows <= 0) {
        $statement->close();
        throw new RuntimeException('Não foi possível inserir na fila.');
      }

      $statement->close();
    }
  );
} catch (DomainException $exception) {
  log_event("DAVEZ_ERRO_DUP_NOME");
  davez_send_error(
    'duplicate_queue_name',
    'Este motoboy já está aguardando na fila da vez.',
    409
  );
} catch (\DaVez\Database\LockUnavailable $exception) {
  log_event("DAVEZ_ORDEM_LOCK_FALHOU");
  header('Retry-After: 2');
  davez_send_error(
    'queue_busy',
    'Fila ocupada. Aguarde e tente novamente.',
    503
  );
} catch (Throwable $exception) {
  log_event("DAVEZ_ERRO_INSERT");
  davez_send_error(
    'queue_unavailable',
    'Fila temporariamente indisponível.',
    500
  );
}

log_event("DAVEZ_OK", [
  "ordem" => $nextOrdem
]);

echo "Ok, você entrou na fila da vez.";
