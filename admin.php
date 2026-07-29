<?php
require_once __DIR__ . '/src/Security/Bootstrap.php';
require_once __DIR__ . '/src/Domain/OperationalCycle.php';
require_once __DIR__ . '/src/Domain/OperationalContext.php';
require_once __DIR__ . '/src/Domain/QueueStateChanged.php';
require_once __DIR__ . '/src/Domain/QueueReorder.php';
require_once __DIR__ . '/src/Domain/ReportSnapshot.php';
require_once __DIR__ . '/src/Database/bootstrap.php';
davez_install_safe_exception_handler();

date_default_timezone_set('America/Sao_Paulo');
davez_start_secure_session(['same_site' => 'Strict']);

function json_out($data, $code=200){
  davez_send_json($data, $code);
}

function admin_render_login(?string $error = null, int $status = 200): never {
  $csrf = htmlspecialchars(davez_csrf_token(), ENT_QUOTES, 'UTF-8');
  $safeError = $error === null
    ? ''
    : '<p role="alert" class="error">'
      . htmlspecialchars($error, ENT_QUOTES, 'UTF-8')
      . '</p>';

  http_response_code($status);
  header('Content-Type: text/html; charset=utf-8');
  header('Cache-Control: no-store, max-age=0');
  header('X-Content-Type-Options: nosniff');
  header('X-Frame-Options: DENY');
  echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8">'
    . '<meta name="viewport" content="width=device-width,initial-scale=1">'
    . '<title>Entrar — Administração DaVez</title><style>'
    . 'body{margin:0;min-height:100dvh;display:grid;place-items:center;'
    . 'font:16px system-ui,sans-serif;background:#070a0f;color:#f4f7fb}'
    . 'main{width:min(92vw,420px);padding:32px;border:1px solid #263142;'
    . 'border-radius:18px;background:#0c111a}'
    . 'label{display:block;margin:18px 0 6px;color:#c7d0dc}'
    . 'input{box-sizing:border-box;width:100%;padding:13px;border:1px solid '
    . '#38465a;border-radius:10px;background:#111824;color:#fff}'
    . 'button{width:100%;margin-top:22px;padding:13px;border:0;border-radius:10px;'
    . 'background:#ff8a1f;color:#17100a;font-weight:800;cursor:pointer}'
    . '.error{padding:12px;border-radius:10px;background:#421d27;color:#ffd8df}'
    . 'small{color:#96a0ae}</style></head><body><main>'
    . '<h1>Administração DaVez</h1><small>Sessão protegida e temporária.</small>'
    . $safeError
    . '<form method="post" action="admin.php" autocomplete="on">'
    . '<input type="hidden" name="admin_auth_action" value="login">'
    . '<input type="hidden" name="_csrf" value="' . $csrf . '">'
    . '<label for="admin-user">Usuário</label>'
    . '<input id="admin-user" name="username" maxlength="128" '
    . 'autocomplete="username" required autofocus>'
    . '<label for="admin-password">Senha</label>'
    . '<input id="admin-password" name="password" type="password" '
    . 'maxlength="1024" autocomplete="current-password" required>'
    . '<button type="submit">Entrar</button></form></main></body></html>';
  exit;
}

$adminAuthAction = is_string($_POST['admin_auth_action'] ?? null)
  ? $_POST['admin_auth_action']
  : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $adminAuthAction === 'login') {
  try {
    davez_assert_allowed_input_keys(
      $_POST,
      ['admin_auth_action', '_csrf', 'username', 'password']
    );
    davez_require_csrf(is_string($_POST['_csrf'] ?? null) ? $_POST['_csrf'] : null);

    $rate = davez_rate_limit_consume(
      'admin-login',
      davez_rate_limit_request_subject(),
      5,
      300
    );

    if (!$rate['allowed']) {
      header('Retry-After: ' . $rate['retry_after']);
      admin_render_login(
        'Muitas tentativas. Aguarde e tente novamente.',
        429
      );
    }

    $username = davez_input_string($_POST, 'username', 1, 128);
    $password = $_POST['password'] ?? null;

    if (
      !is_string($password)
      || strlen($password) < 1
      || strlen($password) > 1024
    ) {
      throw new InvalidArgumentException('Senha inválida.');
    }

    if (!davez_admin_authenticate($username, $password)) {
      admin_render_login('Credenciais inválidas.', 401);
    }

    header('Location: admin.php', true, 303);
    exit;
  } catch (InvalidArgumentException $exception) {
    admin_render_login('Dados de autenticação inválidos.', 400);
  } catch (RuntimeException $exception) {
    admin_render_login('Autenticação temporariamente indisponível.', 503);
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $adminAuthAction === 'logout') {
  davez_require_admin();
  davez_require_csrf(is_string($_POST['_csrf'] ?? null) ? $_POST['_csrf'] : null);
  davez_admin_logout();
  header('Location: admin.php', true, 303);
  exit;
}

if (!davez_admin_session_is_authenticated()) {
  $isAdminApiRequest = (
    isset($_GET['action'])
    || (
      $_SERVER['REQUEST_METHOD'] === 'POST'
      && $adminAuthAction === ''
    )
  );

  if ($isAdminApiRequest) {
    davez_send_error(
      'authentication_required',
      'Sua sessão administrativa expirou. Entre novamente.',
      401
    );
  }

  admin_render_login();
}

include "config.php";
$operationalContext = new \DaVez\Domain\OperationalContext(
  new \DaVez\Domain\OperationalCycle()
);
$operationalStart = $operationalContext->startSql();
$operationalEnd = $operationalContext->endSql();
$operationalDate = $operationalContext->date();

/* ===== garante token sem mexer na lista ===== */
try {
  $s = davez_settings_token_cycle($conn)->loadAndRotate(
    $operationalContext
  );
} catch (Throwable $exception) {
  davez_send_error(
    'settings_unavailable',
    'Configurações temporariamente indisponíveis.',
    500
  );
}

/* ===== helpers métricas ===== */
function metrics_hoje($conn, string $inicio, string $fim){
  $sql = "
    SELECT
      COUNT(*) AS total,
      SUM(CASE WHEN COALESCE(is_closed,0)=0 THEN 1 ELSE 0 END) AS abertos,
      SUM(CASE WHEN COALESCE(is_closed,0)=1 THEN 1 ELSE 0 END) AS fechados,
      MAX(data_hora) AS ultimo
    FROM checkins
    WHERE data_hora >= ?
      AND data_hora < ?
  ";

  $stmt = $conn->prepare($sql);
  if (!$stmt) {
    throw new RuntimeException('Métricas indisponíveis.');
  }
  $stmt->bind_param("ss", $inicio, $fim);
  if (!$stmt->execute()) {
    $stmt->close();
    throw new RuntimeException('Métricas indisponíveis.');
  }
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  $tempo_medio = null;
  $sqlAvg = "
    SELECT AVG(TIMESTAMPDIFF(MINUTE, data_hora, closed_at)) AS tempo_medio_min
    FROM checkins
    WHERE data_hora >= ?
      AND data_hora < ?
      AND COALESCE(is_closed,0)=1
      AND closed_at IS NOT NULL
  ";
  $stmtAvg = $conn->prepare($sqlAvg);
  if (!$stmtAvg) {
    throw new RuntimeException('Métricas indisponíveis.');
  }
  $stmtAvg->bind_param("ss", $inicio, $fim);
  if (!$stmtAvg->execute()) {
    $stmtAvg->close();
    throw new RuntimeException('Métricas indisponíveis.');
  }
  $a = $stmtAvg->get_result()->fetch_assoc();
  $stmtAvg->close();
  if ($a && $a['tempo_medio_min'] !== null) {
    $tempo_medio = (int) round($a['tempo_medio_min']);
  }

  return [
    'total' => (int)($row['total'] ?? 0),
    'abertos' => (int)($row['abertos'] ?? 0),
    'fechados' => (int)($row['fechados'] ?? 0),
    'ultimo' => $row['ultimo'] ?? null,
    'tempo_medio_min' => $tempo_medio,
    'operational_start' => $inicio,
    'operational_end' => $fim
  ];
}

/* ===== actions ===== */
$action = is_string($_GET['action'] ?? null) ? $_GET['action'] : '';
$readActions = [
  'dados',
  'metrics',
  'lista',
  'listar_relatorios',
  'ver_relatorio'
];

if ($action !== '') {
  davez_require_http_method('GET');

  if (!in_array($action, $readActions, true)) {
    json_out(['erro' => 'Ação de leitura inválida.'], 400);
  }

  try {
    davez_assert_allowed_input_keys(
      $_GET,
      $action === 'ver_relatorio' ? ['action', 'id'] : ['action']
    );
  } catch (InvalidArgumentException $exception) {
    json_out(['erro' => 'Parâmetros de leitura inválidos.'], 400);
  }
}

if ($action === "dados") {
  $s2 = davez_settings_token_cycle($conn)->loadAndRotate(
    $operationalContext
  );
  json_out($s2 ?: []);
}

if ($action === "metrics") {
  $s2 = $conn->query("SELECT chamada_aberta FROM settings WHERE id=1")->fetch_assoc();
  $m = metrics_hoje($conn, $operationalStart, $operationalEnd);
  $m['chamada_aberta'] = (int)($s2['chamada_aberta'] ?? 0);
  json_out($m);
}

if ($action === "lista") {
  $inicio = $operationalStart;
  $fim = $operationalEnd;

  $sql = "SELECT id, nome, data_hora, COALESCE(ordem, 999999) AS ordem,
                 COALESCE(is_closed,0) AS is_closed
          FROM checkins
          WHERE data_hora >= ?
            AND data_hora < ?
          ORDER BY
            CASE WHEN COALESCE(is_closed,0)=0 THEN 0 ELSE 1 END ASC,
            ordem ASC,
            data_hora ASC
          LIMIT 500";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("ss", $inicio, $fim);
  $stmt->execute();
  $r = $stmt->get_result();

  $lista = [];
  while ($l = $r->fetch_assoc()) {
    if (!isset($l['is_closed'])) $l['is_closed'] = 0;
    $lista[] = $l;
  }
  json_out($lista);
}

/* ===== Relatórios: listar e ver ===== */
if ($action === "listar_relatorios") {
  $r = $conn->query("
    SELECT id, periodo_inicio, periodo_fim, total_checkins, motoboys_unicos, total_fechados, created_at
    FROM reports
    ORDER BY id DESC
    LIMIT 200
  ");
  $out = [];
  if ($r) {
    while ($row = $r->fetch_assoc()) $out[] = $row;
  }
  json_out($out);
}

if ($action === "ver_relatorio") {
  try {
    $id = davez_input_integer($_GET, 'id', 1, PHP_INT_MAX);
  } catch (InvalidArgumentException $exception) {
    json_out(['erro'=>'ID inválido'], 400);
  }

  $stmt = $conn->prepare("SELECT * FROM reports WHERE id=? LIMIT 1");
  $stmt->bind_param("i", $id);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res->fetch_assoc();

  if (!$row) json_out(['erro'=>'Relatório não encontrado'], 404);

  $payload = json_decode($row['payload_json'] ?? '[]', true);

  json_out([
    'meta' => [
      'id' => $row['id'],
      'periodo_inicio' => $row['periodo_inicio'],
      'periodo_fim' => $row['periodo_fim'],
      'total_checkins' => $row['total_checkins'],
      'motoboys_unicos' => $row['motoboys_unicos'],
      'total_fechados' => $row['total_fechados'],
      'created_at' => $row['created_at'],
    ],
    'payload' => $payload
  ]);
}

/* ===== POST JSON ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === 0) {
  $input = davez_read_json_body(32768);

  try {
    $acao = davez_input_string($input, 'acao', 1, 64);
  } catch (InvalidArgumentException $exception) {
    json_out(['sucesso'=>false,'erro'=>'Ação JSON inválida'], 400);
  }

  $allowedJsonFields = [
    'toggle_chamada' => ['acao', '_csrf'],
    'limpar' => ['acao', '_csrf'],
    'toggle_close' => ['acao', 'id', '_csrf'],
    'apagar_relatorio' => ['acao', 'id', '_csrf'],
    'atualizar_ordem' => ['acao', 'ordem', '_csrf'],
    'add_manual' => ['acao', 'nome', 'obs', '_csrf'],
  ];

  if (!isset($allowedJsonFields[$acao])) {
    json_out(['sucesso'=>false,'erro'=>'Ação JSON desconhecida'], 400);
  }

  try {
    davez_assert_allowed_input_keys($input, $allowedJsonFields[$acao]);
    davez_assert_no_untrusted_identity($input);
  } catch (InvalidArgumentException $exception) {
    json_out(['sucesso'=>false,'erro'=>'Campos de solicitação inválidos.'], 400);
  }

  davez_require_csrf(
    is_string($input['_csrf'] ?? null) ? $input['_csrf'] : null
  );

  try {
    $adminRate = davez_rate_limit_consume(
      'admin-action',
      davez_rate_limit_request_subject(),
      120,
      60
    );
  } catch (RuntimeException $exception) {
    json_out(
      ['sucesso'=>false,'erro'=>'Controle de segurança indisponível.'],
      503
    );
  }

  if (!$adminRate['allowed']) {
    header('Retry-After: ' . $adminRate['retry_after']);
    json_out(
      ['sucesso'=>false,'erro'=>'Muitas ações. Aguarde e tente novamente.'],
      429
    );
  }

  if ($acao === 'toggle_chamada') {
    $updated = $conn->query(
      "UPDATE settings
       SET chamada_inicio = CASE
             WHEN COALESCE(chamada_aberta,0)=0 THEN NOW()
             ELSE chamada_inicio
           END,
           chamada_fim = CASE
             WHEN COALESCE(chamada_aberta,0)=1 THEN NOW()
             ELSE NULL
           END,
           chamada_aberta = CASE
             WHEN COALESCE(chamada_aberta,0)=1 THEN 0
             ELSE 1
           END
       WHERE id=1"
    );

    if (!$updated || $conn->affected_rows !== 1) {
      json_out(
        ['sucesso'=>false, 'erro'=>'Não foi possível alterar a chamada.'],
        500
      );
    }

    json_out(['sucesso' => true]);
  }

  if ($acao === 'limpar') {
    $destructiveRate = davez_rate_limit_consume(
      'admin-destructive',
      davez_rate_limit_request_subject(),
      5,
      300
    );

    if (!$destructiveRate['allowed']) {
      header('Retry-After: ' . $destructiveRate['retry_after']);
      json_out(
        ['sucesso'=>false,'erro'=>'Limite de ações destrutivas atingido.'],
        429
      );
    }

    $lockedTransactions = davez_locked_transaction_runner($conn);

    try {
      $reportId = $lockedTransactions->run(
        'checkins:' . $operationalDate,
        static function () use (
          $conn,
          $operationalStart,
          $operationalEnd
        ): int {
          $settingsLock = $conn->query(
            "SELECT id
             FROM settings
             WHERE id=1
             FOR UPDATE"
          );

          if (!$settingsLock || !$settingsLock->fetch_assoc()) {
            throw new RuntimeException(
              'Configurações indisponíveis para fechamento.'
            );
          }

          $select = $conn->prepare(
            "SELECT id, nome, data_hora,
                    COALESCE(ordem, 999999) AS ordem,
                    COALESCE(is_closed,0) AS is_closed,
                    closed_at
             FROM checkins
             WHERE data_hora >= ?
               AND data_hora < ?
             ORDER BY ordem ASC, data_hora ASC
             FOR UPDATE"
          );

          if (!$select) {
            throw new RuntimeException(
              'Check-ins indisponíveis para relatório.'
            );
          }

          $select->bind_param(
            "ss",
            $operationalStart,
            $operationalEnd
          );

          if (!$select->execute()) {
            $select->close();
            throw new RuntimeException(
              'Check-ins indisponíveis para relatório.'
            );
          }

          $result = $select->get_result();
          $items = [];
          while ($item = $result->fetch_assoc()) {
            $items[] = $item;
          }
          $select->close();

          $payload = \DaVez\Domain\ReportSnapshot::build(
            $operationalStart,
            $operationalEnd,
            $items
          );
          $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
          );
          $total = (int) $payload['total_checkins'];
          $unique = (int) $payload['motoboys_unicos'];
          $closed = (int) $payload['total_fechados'];

          $report = $conn->prepare(
            "INSERT INTO reports
               (periodo_inicio, periodo_fim, total_checkins,
                motoboys_unicos, total_fechados, payload_json)
             VALUES (?, ?, ?, ?, ?, ?)"
          );

          if (!$report) {
            throw new RuntimeException(
              'Relatório indisponível para persistência.'
            );
          }

          $report->bind_param(
            "ssiiis",
            $operationalStart,
            $operationalEnd,
            $total,
            $unique,
            $closed,
            $json
          );

          if (!$report->execute() || $report->affected_rows !== 1) {
            $report->close();
            throw new RuntimeException(
              'Relatório indisponível para persistência.'
            );
          }
          $reportId = (int) $conn->insert_id;
          $report->close();

          $delete = $conn->prepare(
            "DELETE FROM checkins
             WHERE data_hora >= ?
               AND data_hora < ?"
          );

          if (!$delete) {
            throw new RuntimeException(
              'Check-ins indisponíveis para limpeza.'
            );
          }

          $delete->bind_param(
            "ss",
            $operationalStart,
            $operationalEnd
          );

          if (!$delete->execute() || $delete->affected_rows !== $total) {
            $delete->close();
            throw new RuntimeException(
              'A limpeza não corresponde ao relatório.'
            );
          }
          $delete->close();

          $close = $conn->prepare(
            "UPDATE settings
             SET chamada_aberta=0, chamada_fim=NOW()
             WHERE id=1"
          );

          if (!$close || !$close->execute()) {
            if ($close) {
              $close->close();
            }
            throw new RuntimeException(
              'Não foi possível fechar a chamada.'
            );
          }
          $close->close();

          return $reportId;
        }
      );

      json_out([
        'sucesso' => true,
        'report_id' => $reportId,
        'erro' => null
      ]);
    } catch (\DaVez\Database\LockUnavailable $exception) {
      header('Retry-After: 2');
      json_out([
        'sucesso' => false,
        'erro' => 'Fila ocupada. Aguarde e tente novamente.'
      ], 503);
    } catch (Throwable $exception) {
      json_out([
        'sucesso' => false,
        'erro' => 'Não foi possível gerar o relatório e limpar a fila.'
      ], 500);
    }
  }

  if ($acao === 'toggle_close') {
    try {
      $id = davez_input_integer($input, 'id', 1, PHP_INT_MAX);
    } catch (InvalidArgumentException $exception) {
      json_out(['sucesso'=>false, 'erro'=>'ID inválido'], 400);
    }

    $checkin = null;
    $newClosed = 0;
    $removed = 0;
    $allocator = davez_atomic_order_allocator($conn);

    try {
      $allocator->allocateAndPersist(
        'checkins:' . $operationalDate,
        static function () use (
          $conn,
          $id,
          $operationalStart,
          $operationalEnd,
          &$checkin
        ): int {
          $select = $conn->prepare(
            "SELECT id, nome, client_id,
                    COALESCE(is_closed,0) AS is_closed
             FROM checkins
             WHERE id=?
               AND data_hora >= ?
               AND data_hora < ?
             LIMIT 1
             FOR UPDATE"
          );

          if (!$select) {
            throw new RuntimeException(
              'Check-in indisponível para leitura.'
            );
          }

          $select->bind_param(
            "iss",
            $id,
            $operationalStart,
            $operationalEnd
          );

          if (!$select->execute()) {
            $select->close();
            throw new RuntimeException(
              'Check-in indisponível para leitura.'
            );
          }

          $result = $select->get_result();
          $checkin = $result ? $result->fetch_assoc() : null;
          $select->close();

          if (!$checkin) {
            throw new DomainException('checkin_not_found');
          }

          $maximum = $conn->prepare(
            "SELECT COALESCE(MAX(ordem), 0)
             FROM checkins
             WHERE data_hora >= ?
               AND data_hora < ?"
          );

          if (!$maximum) {
            throw new RuntimeException(
              'Fila indisponível para ordenação.'
            );
          }

          $maximum->bind_param(
            "ss",
            $operationalStart,
            $operationalEnd
          );

          if (!$maximum->execute()) {
            $maximum->close();
            throw new RuntimeException(
              'Fila indisponível para ordenação.'
            );
          }

          $maximumOrder = 0;
          $maximum->bind_result($maximumOrder);
          $maximum->fetch();
          $maximum->close();

          return (int) $maximumOrder;
        },
        static function (int $order) use (
          $conn,
          $id,
          $operationalStart,
          $operationalEnd,
          $operationalDate,
          &$checkin,
          &$newClosed,
          &$removed
        ): void {
          $newClosed = (int) ($checkin['is_closed'] ?? 0) === 0
            ? 1
            : 0;
          $closedAtSql = $newClosed === 1 ? 'NOW()' : 'NULL';
          $update = $conn->prepare(
            "UPDATE checkins
             SET is_closed=?,
                 closed_at={$closedAtSql},
                 ordem=?
             WHERE id=?
               AND data_hora >= ?
               AND data_hora < ?
             LIMIT 1"
          );

          if (!$update) {
            throw new RuntimeException(
              'Check-in indisponível para atualização.'
            );
          }

          $update->bind_param(
            "iiiss",
            $newClosed,
            $order,
            $id,
            $operationalStart,
            $operationalEnd
          );

          if (!$update->execute() || $update->affected_rows !== 1) {
            $update->close();
            throw new RuntimeException(
              'Check-in indisponível para atualização.'
            );
          }
          $update->close();

          if ($newClosed !== 1) {
            return;
          }

          $name = trim((string) ($checkin['nome'] ?? ''));
          $clientId = trim((string) ($checkin['client_id'] ?? ''));

          if ($clientId !== '') {
            $delete = $conn->prepare(
              "DELETE FROM fila_da_vez
               WHERE dia=?
                 AND (
                   client_id=?
                   OR LOWER(TRIM(nome))=LOWER(TRIM(?))
                 )"
            );

            if (!$delete) {
              throw new RuntimeException(
                'Fila DaVez indisponível para atualização.'
              );
            }
            $delete->bind_param(
              "sss",
              $operationalDate,
              $clientId,
              $name
            );
          } else {
            $delete = $conn->prepare(
              "DELETE FROM fila_da_vez
               WHERE dia=?
                 AND LOWER(TRIM(nome))=LOWER(TRIM(?))"
            );

            if (!$delete) {
              throw new RuntimeException(
                'Fila DaVez indisponível para atualização.'
              );
            }
            $delete->bind_param("ss", $operationalDate, $name);
          }

          if (!$delete->execute()) {
            $delete->close();
            throw new RuntimeException(
              'Fila DaVez indisponível para atualização.'
            );
          }

          $removed = (int) $delete->affected_rows;
          $delete->close();
        }
      );

      json_out([
        'sucesso' => true,
        'is_closed' => $newClosed,
        'removidos_da_vez' => $removed
      ]);
    } catch (DomainException $exception) {
      json_out([
        'sucesso' => false,
        'erro' => 'Check-in não encontrado no ciclo atual'
      ], 404);
    } catch (\DaVez\Database\LockUnavailable $exception) {
      header('Retry-After: 2');
      json_out([
        'sucesso' => false,
        'erro' => 'Fila ocupada. Aguarde e tente novamente.'
      ], 503);
    } catch (Throwable $exception) {
      json_out([
        'sucesso' => false,
        'erro' => 'Não foi possível atualizar o check-in.'
      ], 500);
    }
  }

  if ($acao === 'apagar_relatorio') {
    try {
      $id = davez_input_integer($input, 'id', 1, PHP_INT_MAX);
    } catch (InvalidArgumentException $exception) {
      json_out(['sucesso'=>false, 'erro'=>'ID inválido'], 400);
    }

    $stmt = $conn->prepare("DELETE FROM reports WHERE id=? LIMIT 1");
    if (!$stmt) {
      json_out(['sucesso'=>false, 'erro'=>'Não foi possível processar a solicitação.'], 500);
    }
    $stmt->bind_param("i", $id);
    $stmt->execute();

    if ($stmt->affected_rows <= 0) {
      json_out(['sucesso'=>false, 'erro'=>'Relatório não encontrado'], 404);
    }

    json_out(['sucesso'=>true]);
  }

  if ($acao === 'atualizar_ordem') {
    try {
      $ordemValidada = \DaVez\Domain\QueueReorder::normalize(
        $input['ordem'] ?? null,
        500
      );
    } catch (InvalidArgumentException $exception) {
      json_out([
        'sucesso' => false,
        'erro' => 'Lista de ordem inválida'
      ], 400);
    }

    $lockedTransactions = davez_locked_transaction_runner($conn);

    try {
      $lockedTransactions->run(
        'checkins:' . $operationalDate,
        static function () use (
          $conn,
          $ordemValidada,
          $operationalStart,
          $operationalEnd
        ): void {
          $current = $conn->prepare(
            "SELECT id
             FROM checkins
             WHERE data_hora >= ?
               AND data_hora < ?
             ORDER BY id
             FOR UPDATE"
          );

          if (!$current) {
            throw new RuntimeException(
              'Fila indisponível para leitura.'
            );
          }

          $current->bind_param(
            "ss",
            $operationalStart,
            $operationalEnd
          );

          if (!$current->execute()) {
            $current->close();
            throw new RuntimeException(
              'Fila indisponível para leitura.'
            );
          }

          $result = $current->get_result();
          $currentIds = [];
          while ($row = $result->fetch_assoc()) {
            $currentIds[] = (int) $row['id'];
          }
          $current->close();

          \DaVez\Domain\QueueReorder::assertExactSet(
            $ordemValidada,
            $currentIds
          );

          $update = $conn->prepare(
            "UPDATE checkins
             SET ordem=?
             WHERE id=?
               AND data_hora >= ?
               AND data_hora < ?"
          );

          if (!$update) {
            throw new RuntimeException(
              'Fila indisponível para atualização.'
            );
          }

          foreach (
            \DaVez\Domain\QueueReorder::positions($ordemValidada)
            as $id => $position
          ) {
            $update->bind_param(
              "iiss",
              $position,
              $id,
              $operationalStart,
              $operationalEnd
            );

            if (!$update->execute()) {
              $update->close();
              throw new RuntimeException(
                'Falha ao atualizar a fila.'
              );
            }
          }
          $update->close();

          $verify = $conn->prepare(
            "SELECT id, ordem
             FROM checkins
             WHERE data_hora >= ?
               AND data_hora < ?
             ORDER BY ordem ASC, id ASC
             FOR UPDATE"
          );

          if (!$verify) {
            throw new RuntimeException(
              'Fila indisponível para verificação.'
            );
          }

          $verify->bind_param(
            "ss",
            $operationalStart,
            $operationalEnd
          );

          if (!$verify->execute()) {
            $verify->close();
            throw new RuntimeException(
              'Fila indisponível para verificação.'
            );
          }

          $result = $verify->get_result();
          $verifiedIds = [];
          $expectedPosition = 1;

          while ($row = $result->fetch_assoc()) {
            if ((int) $row['ordem'] !== $expectedPosition) {
              $verify->close();
              throw new RuntimeException(
                'A sequência final da fila é inválida.'
              );
            }
            $verifiedIds[] = (int) $row['id'];
            $expectedPosition++;
          }
          $verify->close();

          if ($verifiedIds !== $ordemValidada) {
            throw new RuntimeException(
              'A ordem final diverge da solicitação.'
            );
          }
        }
      );

      json_out(['sucesso'=>true]);
    } catch (\DaVez\Domain\QueueStateChanged $exception) {
      json_out([
        'sucesso' => false,
        'erro' => 'A fila mudou. Atualize a página e tente novamente.'
      ], 409);
    } catch (\DaVez\Database\LockUnavailable $exception) {
      header('Retry-After: 2');
      json_out([
        'sucesso' => false,
        'erro' => 'Fila ocupada. Aguarde e tente novamente.'
      ], 503);
    } catch (Throwable $exception) {
      json_out(['sucesso'=>false, 'erro'=>'Falha ao atualizar ordem'], 500);
    }
  }

  if ($acao === 'add_manual') {
    try {
      $nome = davez_input_string($input, 'nome', 2, 80);
      $obs = davez_input_string($input, 'obs', 0, 120, false) ?? '';
    } catch (InvalidArgumentException $exception) {
      json_out(['sucesso'=>false,'erro'=>'Dados da inclusão manual inválidos.'], 400);
    }

    $insertedId = 0;
    $allocator = davez_atomic_order_allocator($conn);

    try {
      $allocator->allocateAndPersist(
        'checkins:' . $operationalDate,
        static function () use (
          $conn,
          $nome,
          $operationalStart,
          $operationalEnd
        ): int {
          $duplicate = $conn->prepare(
            "SELECT id
             FROM checkins
             WHERE LOWER(TRIM(nome))=LOWER(TRIM(?))
               AND data_hora >= ?
               AND data_hora < ?
             LIMIT 1"
          );

          if (!$duplicate) {
            throw new RuntimeException(
              'Fila indisponível para validação.'
            );
          }

          $duplicate->bind_param(
            "sss",
            $nome,
            $operationalStart,
            $operationalEnd
          );

          if (!$duplicate->execute()) {
            $duplicate->close();
            throw new RuntimeException(
              'Fila indisponível para validação.'
            );
          }

          $duplicate->store_result();
          $exists = $duplicate->num_rows > 0;
          $duplicate->close();

          if ($exists) {
            throw new DomainException('duplicate_name');
          }

          $maximum = $conn->prepare(
            "SELECT COALESCE(MAX(ordem), 0)
             FROM checkins
             WHERE data_hora >= ?
               AND data_hora < ?"
          );

          if (!$maximum) {
            throw new RuntimeException(
              'Fila indisponível para ordenação.'
            );
          }

          $maximum->bind_param(
            "ss",
            $operationalStart,
            $operationalEnd
          );

          if (!$maximum->execute()) {
            $maximum->close();
            throw new RuntimeException(
              'Fila indisponível para ordenação.'
            );
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
          $obs,
          &$insertedId
        ): void {
          $insert = $conn->prepare(
            "INSERT INTO checkins
               (nome, data_hora, ordem, is_closed, closed_at, obs)
             VALUES (?, NOW(), ?, 0, NULL, ?)"
          );

          if (!$insert) {
            throw new RuntimeException(
              'Fila indisponível para inserção.'
            );
          }

          $insert->bind_param("sis", $nome, $order, $obs);

          if (!$insert->execute() || $insert->affected_rows !== 1) {
            $insert->close();
            throw new RuntimeException(
              'Fila indisponível para inserção.'
            );
          }

          $insertedId = (int) $conn->insert_id;
          $insert->close();
        }
      );

      json_out(['sucesso'=>true, 'id'=>$insertedId]);
    } catch (DomainException $exception) {
      json_out([
        'sucesso' => false,
        'erro' => 'Já existe um check-in com esse nome no ciclo atual.'
      ], 409);
    } catch (\DaVez\Database\LockUnavailable $exception) {
      header('Retry-After: 2');
      json_out([
        'sucesso' => false,
        'erro' => 'Fila ocupada. Aguarde e tente novamente.'
      ], 503);
    } catch (Throwable $exception) {
      json_out([
        'sucesso' => false,
        'erro' => 'Não foi possível adicionar o registro.'
      ], 500);
    }
  }

  json_out(['sucesso'=>false,'erro'=>'Ação JSON desconhecida'], 400);
}

/* ===== POST FORM: salvar config ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    davez_assert_allowed_input_keys(
      $_POST,
      ['form_action', '_csrf', 'token', 'lat', 'lng', 'raio']
    );
    davez_assert_no_untrusted_identity($_POST);
    davez_require_csrf(
      is_string($_POST['_csrf'] ?? null) ? $_POST['_csrf'] : null
    );

    if (($_POST['form_action'] ?? '') !== 'save_settings') {
      throw new InvalidArgumentException('Ação de formulário inválida.');
    }

    $token = davez_input_string($_POST, 'token', 0, 16);
    if ($token !== '' && preg_match('/\A[A-Z0-9]{4,16}\z/', $token) !== 1) {
      throw new InvalidArgumentException('Token inválido.');
    }

    $lat = davez_input_float($_POST, 'lat', -90, 90);
    $lng = davez_input_float($_POST, 'lng', -180, 180);
    $raio = davez_input_integer($_POST, 'raio', 1, 100000);

    $settingsRate = davez_rate_limit_consume(
      'admin-settings',
      davez_rate_limit_request_subject(),
      20,
      300
    );

    if (!$settingsRate['allowed']) {
      header('Retry-After: ' . $settingsRate['retry_after']);
      json_out(
        ['sucesso'=>false,'erro'=>'Muitas alterações de configuração.'],
        429
      );
    }
  } catch (InvalidArgumentException $exception) {
    json_out(['sucesso'=>false,'erro'=>'Configurações inválidas.'], 400);
  } catch (RuntimeException $exception) {
    json_out(['sucesso'=>false,'erro'=>'Controle de segurança indisponível.'], 503);
  }

  $stmt = $conn->prepare("UPDATE settings SET token=?, lat_base=?, lng_base=?, raio=? WHERE id=1");
  $stmt->bind_param("sddi", $token, $lat, $lng, $raio);
  $stmt->execute();
  json_out(['sucesso' => true]);
}

/* ===== HTML ===== */
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>Admin - Chamada Motoboys</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<style>
:root{
  color-scheme:light;
  --canvas:#e9ecec;
  --canvas-deep:#dde2e1;
  --surface:#f8f9f7;
  --surface-raised:#ffffff;
  --surface-muted:#eef1ef;
  --ink:#17201e;
  --ink-soft:#52605c;
  --line:#c9d0cd;
  --line-strong:#aab5b1;
  --accent:#0f766e;
  --accent-strong:#0a5b55;
  --accent-soft:#d9efeb;
  --warning:#a34d0b;
  --warning-soft:#fff0dc;
  --danger:#a1272f;
  --danger-strong:#821d25;
  --danger-soft:#fde7e8;
  --success:#246b42;
  --success-soft:#def1e5;
  --token-bg:#101817;
  --token-ink:#83f0c5;
  --shadow:0 18px 42px rgba(40,54,50,.09);
  --shadow-small:0 8px 22px rgba(40,54,50,.08);
  --radius-xl:24px;
  --radius-lg:18px;
  --radius-md:13px;
  --ease:cubic-bezier(.22,.78,.3,1);
  --focus:#0b65d8;
}
@media (prefers-color-scheme:dark){
  :root{
    color-scheme:dark;
    --canvas:#111715;
    --canvas-deep:#0b100f;
    --surface:#18201e;
    --surface-raised:#1d2724;
    --surface-muted:#222d29;
    --ink:#eef5f1;
    --ink-soft:#aab8b3;
    --line:#35433e;
    --line-strong:#52645d;
    --accent:#59c7b7;
    --accent-strong:#7dd8ca;
    --accent-soft:#193f39;
    --warning:#ffc077;
    --warning-soft:#4a2d14;
    --danger:#ff9ba1;
    --danger-strong:#ffb8bd;
    --danger-soft:#4b2227;
    --success:#80d8a3;
    --success-soft:#1d422d;
    --token-bg:#070c0b;
    --token-ink:#8ef3ca;
    --shadow:0 20px 48px rgba(0,0,0,.25);
    --shadow-small:0 10px 26px rgba(0,0,0,.22);
    --focus:#7cb8ff;
  }
}
*{box-sizing:border-box}
html{scroll-behavior:smooth}
body{
  margin:0;
  min-width:320px;
  min-height:100dvh;
  background:var(--canvas);
  color:var(--ink);
  font-family:"Aptos","Segoe UI Variable","SF Pro Text",system-ui,sans-serif;
  font-size:16px;
  line-height:1.5;
}
button,input{font:inherit}
button{min-height:44px}
button:not(:disabled){cursor:pointer}
button:disabled{cursor:not-allowed;opacity:.48}
:focus-visible{
  outline:3px solid var(--focus);
  outline-offset:3px;
}
.skip-link{
  position:fixed;
  left:16px;
  top:12px;
  z-index:60;
  padding:10px 14px;
  border-radius:10px;
  background:var(--ink);
  color:var(--surface-raised);
  transform:translateY(-160%);
}
.skip-link:focus{transform:translateY(0)}
.container{
  width:min(100%,1440px);
  margin:0 auto;
  padding:clamp(18px,3vw,42px);
}
.admin-header{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:20px;
  margin-bottom:22px;
}
.titulo-admin{
  display:flex;
  align-items:center;
  gap:14px;
  margin:0;
  font-size:clamp(1.45rem,3vw,2.25rem);
  line-height:1.05;
  letter-spacing:-.035em;
}
.titulo-admin img{
  width:clamp(72px,9vw,104px);
  height:auto;
}
.eyebrow{
  margin:0 0 4px;
  color:var(--ink-soft);
  font-size:.72rem;
  font-weight:800;
  letter-spacing:.14em;
  text-transform:uppercase;
}
.logout-form{display:flex;justify-content:flex-end}
.tabs-wrap{
  position:sticky;
  top:0;
  z-index:20;
  margin:0 0 24px;
  padding:8px;
  border:1px solid var(--line);
  border-radius:16px;
  background:color-mix(in srgb,var(--canvas) 90%,transparent);
  box-shadow:var(--shadow-small);
  backdrop-filter:blur(12px);
}
.tabs{
  display:grid;
  grid-template-columns:repeat(4,minmax(0,1fr));
  gap:6px;
}
.tab{
  min-width:0;
  padding:10px 14px;
  border:1px solid transparent;
  border-radius:11px;
  background:transparent;
  color:var(--ink-soft);
  font-weight:750;
  transition:background-color .2s var(--ease),color .2s var(--ease),border-color .2s var(--ease),transform .2s var(--ease);
}
.tab:hover{background:var(--surface-muted);color:var(--ink)}
.tab[aria-selected="true"]{
  border-color:var(--line);
  background:var(--surface-raised);
  color:var(--accent-strong);
  box-shadow:0 2px 8px rgba(30,50,45,.08);
}
.section{display:block}
.section[hidden]{display:none}
.token-box{
  position:relative;
  overflow:hidden;
  padding:clamp(24px,5vw,46px);
  border:1px solid #283633;
  border-radius:var(--radius-xl);
  background:var(--token-bg);
  color:var(--token-ink);
  text-align:center;
  box-shadow:var(--shadow),inset 0 0 0 1px rgba(255,255,255,.04);
  margin-bottom:16px;
}
.token-box::after{
  content:"";
  position:absolute;
  inset:10px;
  border:1px solid rgba(131,240,197,.13);
  border-radius:calc(var(--radius-xl) - 8px);
  pointer-events:none;
}
.token-box output{
  position:relative;
  z-index:1;
  display:block;
  font-family:"Cascadia Mono","SFMono-Regular",Consolas,monospace;
  font-size:clamp(2.4rem,9vw,5.25rem);
  font-weight:800;
  letter-spacing:clamp(.12em,2vw,.3em);
  line-height:1;
  overflow-wrap:anywhere;
}
.token-box small{
  position:relative;
  z-index:1;
  display:block;
  margin-top:18px;
  color:#c4d2cd;
}
.dash{
  display:grid;
  grid-template-columns:repeat(6,minmax(0,1fr));
  gap:12px;
  margin-bottom:16px;
}
.dash .mcard:first-child{grid-column:span 2}
.mcard,.card{
  border:1px solid var(--line);
  background:var(--surface);
  box-shadow:var(--shadow-small),inset 0 0 0 1px color-mix(in srgb,var(--surface-raised) 72%,transparent);
}
.mcard{
  min-height:132px;
  padding:18px;
  border-radius:var(--radius-lg);
}
.card{
  padding:clamp(18px,3vw,28px);
  border-radius:var(--radius-xl);
  margin-bottom:16px;
}
.card h2,.card h3{margin:0 0 8px;letter-spacing:-.02em}
.card p{margin:8px 0 16px}
.dash .k{
  color:var(--ink-soft);
  font-size:.72rem;
  font-weight:800;
  letter-spacing:.1em;
  text-transform:uppercase;
}
.dash .v{
  margin-top:8px;
  color:var(--ink);
  font-size:clamp(1.45rem,3vw,2.15rem);
  font-weight:850;
  letter-spacing:-.035em;
}
.dash .sub{margin-top:6px;color:var(--ink-soft);font-size:.78rem}
.actions,.toolbar,.item-actions,.order-actions{
  display:flex;
  flex-wrap:wrap;
  align-items:center;
  gap:9px;
}
.toolbar{justify-content:space-between}
button{
  padding:10px 16px;
  border:1px solid transparent;
  border-radius:11px;
  background:var(--surface-muted);
  color:var(--ink);
  font-weight:750;
  transition:transform .2s var(--ease),background-color .2s var(--ease),border-color .2s var(--ease),box-shadow .2s var(--ease);
}
button:not(:disabled):hover{transform:translateY(-1px)}
.btn-toggle,.save,.btn-primary{
  background:var(--accent);
  color:#fff;
}
.btn-toggle:hover,.save:hover,.btn-primary:hover{background:var(--accent-strong)}
.btn-clear,.btn-warning{background:var(--warning);color:#fff}
.btn-clear:hover,.btn-warning:hover{background:#843c08}
.btn-secondary{
  border-color:var(--line);
  background:var(--surface-raised);
  color:var(--ink);
}
.btn-danger,.mini-btn.fechar{background:var(--danger);color:#fff}
.btn-danger:hover,.mini-btn.fechar:hover{background:var(--danger-strong)}
.btn-add,.mini-btn.reabrir{background:var(--success);color:#fff}
.save{width:100%;margin-top:8px}
.mini-btn,.order-btn{padding:8px 11px;font-size:.78rem}
.order-btn{
  min-width:44px;
  border-color:var(--line);
  background:var(--surface-raised);
}
input{
  width:100%;
  min-height:48px;
  margin:6px 0 0;
  padding:11px 13px;
  border:1px solid var(--line-strong);
  border-radius:11px;
  background:var(--surface-raised);
  color:var(--ink);
}
input::placeholder{color:color-mix(in srgb,var(--ink-soft) 80%,transparent)}
.field{min-width:0}
.field label{display:block;font-weight:750}
.field-help{display:block;margin:4px 0 2px;color:var(--ink-soft);font-size:.82rem}
.row{
  display:grid;
  grid-template-columns:minmax(0,1fr) minmax(0,1fr) auto;
  gap:14px;
  align-items:end;
}
.row-action{min-width:180px}
small.mini,.mini{color:var(--ink-soft);font-size:.78rem}
.status-pill,.badge,.badge2{
  display:inline-flex;
  align-items:center;
  min-height:28px;
  padding:4px 9px;
  border:1px solid transparent;
  border-radius:999px;
  font-size:.7rem;
  font-weight:850;
  letter-spacing:.035em;
}
.status-open,.badge.aberto,.badge2.ok{
  border-color:color-mix(in srgb,var(--success) 35%,transparent);
  background:var(--success-soft);
  color:var(--success);
}
.status-closed,.badge.fechado,.badge2.no{
  border-color:color-mix(in srgb,var(--danger) 35%,transparent);
  background:var(--danger-soft);
  color:var(--danger);
}
.queue-list,.stack{
  margin:0;
  padding:0;
  list-style:none;
}
.queue-list{
  max-height:480px;
  overflow:auto;
  overscroll-behavior:contain;
  border:1px solid var(--line);
  border-radius:var(--radius-lg);
  background:var(--canvas-deep);
}
.queue-item,.dv-item,.report-item,.delivery-item,.state-row{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:14px;
  margin:8px;
  padding:12px;
  border:1px solid var(--line);
  border-radius:13px;
  background:var(--surface-raised);
}
.queue-item{cursor:grab}
.queue-item:active{cursor:grabbing}
.queue-main,.item-copy{display:flex;align-items:center;gap:10px;min-width:0}
.item-copy{flex:1}
.item-text{min-width:0;flex:1}
.nome{display:block;font-weight:800;overflow:hidden;text-overflow:ellipsis}
.nome.fechado{color:var(--danger)}
.ordem{
  display:inline-grid;
  place-items:center;
  min-width:38px;
  min-height:38px;
  border:1px solid var(--line);
  border-radius:10px;
  color:var(--ink-soft);
  font-family:"Cascadia Mono","SFMono-Regular",Consolas,monospace;
  font-size:.78rem;
  font-weight:800;
}
.stack{display:grid;gap:8px}
.dv-drag{
  display:grid;
  place-items:center;
  min-width:34px;
  color:var(--ink-soft);
  cursor:grab;
  font-size:1.2rem;
  user-select:none;
}
.report-list{display:grid;gap:8px}
.report-copy{min-width:0}
.table-scroll{
  max-height:420px;
  overflow:auto;
  overscroll-behavior:contain;
  border:1px solid var(--line);
  border-radius:var(--radius-lg);
  background:var(--surface-raised);
}
.report-table{width:100%;border-collapse:collapse;min-width:720px}
.report-table caption{
  padding:12px 14px;
  color:var(--ink-soft);
  text-align:left;
  font-size:.82rem;
}
.report-table th,.report-table td{
  padding:11px 13px;
  border-bottom:1px solid var(--line);
  text-align:left;
  vertical-align:top;
}
.report-table thead th{
  position:sticky;
  top:0;
  z-index:1;
  background:var(--surface-muted);
  color:var(--ink-soft);
  font-size:.72rem;
  letter-spacing:.06em;
  text-transform:uppercase;
}
.report-table tbody tr:last-child td{border-bottom:0}
.report-table .is-closed{color:var(--danger);font-weight:750}
.state-row{
  min-height:78px;
  justify-content:center;
  color:var(--ink-soft);
  text-align:center;
  cursor:default;
}
.state-error{border-color:color-mix(in srgb,var(--danger) 40%,var(--line));color:var(--danger)}
[aria-busy="true"]{cursor:progress}
.toast{
  position:fixed;
  right:clamp(12px,3vw,28px);
  bottom:clamp(12px,3vw,28px);
  z-index:50;
  width:min(420px,calc(100vw - 24px));
  padding:13px 16px;
  border:1px solid var(--line-strong);
  border-radius:13px;
  background:var(--ink);
  color:var(--surface-raised);
  box-shadow:var(--shadow);
  opacity:0;
  pointer-events:none;
  transform:translateY(12px);
  transition:opacity .22s var(--ease),transform .22s var(--ease);
}
.toast[data-tone="error"]{background:var(--danger-strong);color:#fff}
.toast.show{opacity:1;transform:translateY(0)}
.dialog-layer{
  position:fixed;
  inset:0;
  z-index:70;
  display:grid;
  place-items:center;
  padding:20px;
  background:rgba(5,12,10,.68);
}
.dialog-layer[hidden]{display:none}
.dialog{
  width:min(100%,480px);
  padding:24px;
  border:1px solid var(--line-strong);
  border-radius:var(--radius-xl);
  background:var(--surface-raised);
  color:var(--ink);
  box-shadow:0 28px 80px rgba(0,0,0,.35),inset 0 0 0 1px color-mix(in srgb,var(--surface) 80%,transparent);
}
.dialog h2{margin:0 0 8px;letter-spacing:-.025em}
.dialog p{margin:0 0 22px;color:var(--ink-soft)}
.dialog-actions{display:flex;justify-content:flex-end;gap:10px}
.hidden{display:none !important}
.system-signature{
  position:fixed;
  right:12px;
  bottom:7px;
  z-index:2;
  color:var(--ink-soft);
  font-family:"Cascadia Mono","SFMono-Regular",Consolas,monospace;
  font-size:.65rem;
  opacity:.5;
  pointer-events:none;
}
.sr-only{
  position:absolute;
  width:1px;
  height:1px;
  padding:0;
  margin:-1px;
  overflow:hidden;
  clip:rect(0,0,0,0);
  white-space:nowrap;
  border:0;
}
@media (max-width:900px){
  .dash{grid-template-columns:repeat(2,minmax(0,1fr))}
  .dash .mcard:first-child{grid-column:span 2}
  .row{grid-template-columns:1fr 1fr}
  .row-action{grid-column:1/-1}
  .row-action button{width:100%}
  .queue-item,.dv-item,.report-item{align-items:flex-start;flex-direction:column}
  .item-actions{width:100%;justify-content:flex-end}
}
@media (max-width:640px){
  .container{padding:16px 12px 40px}
  .admin-header{align-items:flex-start}
  .titulo-admin img{width:68px}
  .tabs-wrap{margin-inline:-4px;padding:5px}
  .tabs{grid-template-columns:1fr 1fr}
  .tab{padding-inline:8px;font-size:.84rem}
  .row,.dash{grid-template-columns:1fr}
  .dash .mcard:first-child{grid-column:auto}
  .actions>button,.logout-form button{width:100%}
  .logout-form{width:100%}
  .admin-header{flex-direction:column}
  .queue-main{align-items:flex-start;flex-direction:column;width:100%}
  .queue-item,.dv-item,.delivery-item,.report-item{margin:6px;padding:11px}
  .item-actions{justify-content:flex-start}
  .order-actions{width:100%}
  .order-actions .order-btn{flex:1}
  .dialog-actions{flex-direction:column-reverse}
  .dialog-actions button{width:100%}
}
@media (prefers-reduced-motion:reduce){
  html{scroll-behavior:auto}
  *,*::before,*::after{
    scroll-behavior:auto !important;
    transition-duration:.01ms !important;
    animation-duration:.01ms !important;
    animation-iteration-count:1 !important;
  }
}
</style>
</head>
<body>
<a class="skip-link" href="#admin-content">Ir para o conteúdo</a>
<main class="container" id="admin-content">
  <header class="admin-header">
    <div>
      <p class="eyebrow">Operação em tempo real</p>
      <h1 class="titulo-admin">
        <img src="img/logo.png" alt="DaVez">
        <span>Painel Administrativo</span>
      </h1>
    </div>
    <form method="post" action="admin.php" class="logout-form">
      <input type="hidden" name="admin_auth_action" value="logout">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars(davez_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
      <button type="submit" class="btn-secondary">Encerrar sessão</button>
    </form>
  </header>

  <nav class="tabs-wrap" aria-label="Seções administrativas">
    <div class="tabs" role="tablist" aria-label="Painel administrativo">
      <button type="button" class="tab active" id="tab-chamada" role="tab"
        aria-controls="chamada" aria-selected="true" tabindex="0" data-tab="chamada">
        <span aria-hidden="true">📋</span> Chamada
      </button>
      <button type="button" class="tab" id="tab-davez" role="tab"
        aria-controls="davez" aria-selected="false" tabindex="-1" data-tab="davez">
        <span aria-hidden="true">🚚</span> Da vez
      </button>
      <button type="button" class="tab" id="tab-relatorio" role="tab"
        aria-controls="relatorio" aria-selected="false" tabindex="-1" data-tab="relatorio">
        <span aria-hidden="true">📄</span> Relatórios
      </button>
      <button type="button" class="tab" id="tab-config" role="tab"
        aria-controls="config" aria-selected="false" tabindex="-1" data-tab="config">
        <span aria-hidden="true">⚙️</span> Configurações
      </button>
    </div>
  </nav>

  <section id="chamada" class="section active" role="tabpanel"
    aria-labelledby="tab-chamada" tabindex="0">
    <div class="token-box" aria-labelledby="token-title">
      <span class="sr-only" id="token-title">Token operacional atual</span>
      <output id="tokenDisplay" aria-live="polite">----</output>
      <small>Atualiza automaticamente. Próxima troca em
        <strong id="contador">--:--:--</strong>
      </small>
    </div>

    <div class="dash" id="dash" aria-label="Métricas do ciclo" aria-busy="true">
      <article class="mcard">
        <div class="k">Chamada</div>
        <div class="v"><span class="badge2 no" id="mStatus">Carregando</span></div>
        <div class="sub">Status atual</div>
      </article>
      <article class="mcard">
        <div class="k">Total ciclo</div>
        <div class="v" id="mTotal">0</div>
        <div class="sub">Check-ins</div>
      </article>
      <article class="mcard">
        <div class="k">Na fila</div>
        <div class="v" id="mAbertos">0</div>
        <div class="sub">Abertos</div>
      </article>
      <article class="mcard">
        <div class="k">Finalizados</div>
        <div class="v" id="mFechados">0</div>
        <div class="sub">Fechados</div>
      </article>
      <article class="mcard">
        <div class="k">Último</div>
        <div class="v" id="mUltimo">--:--</div>
        <div class="sub" id="mTempoMedio">Tempo médio: --</div>
      </article>
    </div>

    <section class="card" aria-labelledby="chamada-actions-title">
      <h2 class="sr-only" id="chamada-actions-title">Ações da chamada</h2>
      <div class="actions">
        <button type="button" class="btn-toggle" id="btnToggle">Carregando...</button>
        <span id="statusLista" class="status-pill status-closed" role="status">Lista fechada</span>
        <button type="button" class="btn-secondary" id="btnShowManual"
          aria-expanded="false" aria-controls="manualBox">Adicionar manualmente</button>
        <button type="button" class="btn-clear" id="btnClear">Limpar lista e salvar relatório</button>
      </div>
      <p><small class="mini">Atualização automática a cada 12 segundos. O ciclo operacional vira às 06:00.</small></p>
    </section>

    <section class="card hidden" id="manualBox" aria-labelledby="manual-title">
      <h2 id="manual-title">Adicionar motoboy manualmente</h2>
      <p><small class="mini">Use este fluxo quando o check-in pelo dispositivo não estiver disponível.</small></p>
      <form id="manualForm">
        <div class="row">
          <div class="field">
            <label for="manualNome">Nome</label>
            <input id="manualNome" name="nome" type="text" maxlength="160"
              autocomplete="off" placeholder="Ex.: João Motoboy" required>
          </div>
          <div class="field">
            <label for="manualObs">Observação <span class="mini">(opcional)</span></label>
            <input id="manualObs" name="observacao" type="text" maxlength="240"
              autocomplete="off" placeholder="Ex.: sem bateria">
          </div>
          <div class="row-action">
            <button type="submit" class="btn-add" id="btnAddManual">Adicionar à lista</button>
          </div>
        </div>
      </form>
      <small class="mini">A entrada será criada como <strong>aberta</strong>, com o horário atual.</small>
    </section>

    <section class="card" aria-labelledby="queue-title">
      <div class="toolbar">
        <div>
          <h2 id="queue-title">Ordem de chegada</h2>
          <p><small class="mini">Arraste com o ponteiro ou use os botões Subir e Descer.</small></p>
        </div>
      </div>
      <ul id="lista" class="queue-list" tabindex="0" aria-label="Ordem de chegada"
        aria-live="polite" aria-busy="true">
        <li class="state-row" data-state="loading">Carregando a lista...</li>
      </ul>
    </section>
  </section>

  <section id="davez" class="section" role="tabpanel"
    aria-labelledby="tab-davez" tabindex="0" hidden>
    <section class="card" aria-labelledby="davez-title">
      <div class="toolbar">
        <div>
          <h2 id="davez-title">Fila “Da vez”</h2>
          <p><small class="mini">Quem está na fila pode sair. Quem está em entrega fica temporariamente inelegível.</small></p>
        </div>
        <button type="button" class="btn-secondary" id="btnRefreshDavez">Atualizar</button>
      </div>
      <div id="dvDaVezBox" role="status" aria-live="polite">
        <span class="badge2 no">Carregando</span>
      </div>
    </section>

    <section class="card" aria-labelledby="davez-queue-title">
      <h2 id="davez-queue-title">Na fila</h2>
      <p><small class="mini">Arraste pelo marcador ou use os botões Subir e Descer.</small></p>
      <div id="dvFila" class="stack" role="list" aria-live="polite" aria-busy="false">
        <div class="state-row" role="listitem" data-state="empty">Abra esta seção para carregar a fila.</div>
      </div>
    </section>

    <section class="card" aria-labelledby="delivery-title">
      <h2 id="delivery-title">Em entrega</h2>
      <div id="dvEntrega" class="stack" role="list" aria-live="polite" aria-busy="false">
        <div class="state-row" role="listitem" data-state="empty">Nenhuma entrega carregada.</div>
      </div>
    </section>
  </section>

  <section id="relatorio" class="section" role="tabpanel"
    aria-labelledby="tab-relatorio" tabindex="0" hidden>
    <section class="card" aria-labelledby="report-title">
      <h2 id="report-title">Relatórios salvos</h2>
      <p><small class="mini">Abra um relatório para consultar os detalhes sem sair do painel.</small></p>
      <div id="lastReportBox" aria-live="polite" aria-busy="true">
        <div class="state-row" data-state="loading">Carregando relatórios...</div>
      </div>
    </section>
  </section>

  <section id="config" class="section" role="tabpanel"
    aria-labelledby="tab-config" tabindex="0" hidden>
    <section class="card" aria-labelledby="config-title">
      <h2 id="config-title">Configurações da lista</h2>
      <form id="configForm">
        <div class="field">
          <label for="token">Token atual</label>
          <small class="field-help" id="token-help">Código usado pelos motoboys. A rotação ocorre a cada três dias, às 06:00.</small>
          <input id="token" name="token" type="text" maxlength="64"
            aria-describedby="token-help" autocomplete="off" placeholder="Ex.: A1B2C3">
        </div>
        <div class="field">
          <label for="lat">Latitude base</label>
          <small class="field-help" id="lat-help">Coordenada do ponto onde o check-in é validado.</small>
          <input id="lat" name="lat" type="text" inputmode="decimal"
            aria-describedby="lat-help" autocomplete="off" placeholder="Ex.: -23.550520">
        </div>
        <div class="field">
          <label for="lng">Longitude base</label>
          <small class="field-help" id="lng-help">Coordenada do ponto onde o check-in é validado.</small>
          <input id="lng" name="lng" type="text" inputmode="decimal"
            aria-describedby="lng-help" autocomplete="off" placeholder="Ex.: -46.633308">
        </div>
        <div class="field">
          <label for="raio">Raio permitido, em metros</label>
          <small class="field-help" id="raio-help">Distância máxima da base para permitir a entrada na lista.</small>
          <input id="raio" name="raio" type="number" min="1" max="100000"
            aria-describedby="raio-help" inputmode="numeric" placeholder="Ex.: 100">
        </div>
        <button type="submit" class="save" id="btnSaveSettings">Salvar configurações</button>
      </form>
    </section>
  </section>
</main>

<div class="toast" id="toast" role="status" aria-live="polite" aria-atomic="true"></div>

<div class="dialog-layer" id="adminDialogLayer" hidden>
  <div class="dialog" id="adminDialog" role="dialog" aria-modal="true"
    aria-labelledby="adminDialogTitle" aria-describedby="adminDialogMessage" tabindex="-1">
    <h2 id="adminDialogTitle">Confirmar ação</h2>
    <p id="adminDialogMessage"></p>
    <div class="dialog-actions">
      <button type="button" class="btn-secondary" id="adminDialogCancel">Cancelar</button>
      <button type="button" class="btn-primary" id="adminDialogConfirm">Confirmar</button>
    </div>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.0/Sortable.min.js"></script>
<script>
let carregando = false;
let pausado = false;
const CSRF_TOKEN = <?= json_encode(davez_csrf_token(), JSON_UNESCAPED_SLASHES) ?>;
const DV_LIST_URL = "DaVez/listar.php?v=1";
const DV_SAIR_URL = "DaVez/sair.php?v=1";
const DV_REORDER_URL = "DaVez/reordenar.php?v=1";

let dvCarregando = false;
let dvLast = 0;
let dvSortable = null;
let dvPausado = false;

let tokenCycleEndAt = null;
let sortable = null;
let toastTimer = null;
let dialogResolver = null;
let dialogPreviousFocus = null;
let sessionRedirecting = false;

class AdminRequestError extends Error {
  constructor(message, status=0, data=null){
    super(message);
    this.name = 'AdminRequestError';
    this.status = status;
    this.data = data;
  }
}

class AdminAuthenticationRequiredError extends AdminRequestError {
  constructor(message){
    super(message, 401, null);
    this.name = 'AdminAuthenticationRequiredError';
  }
}

function getErrorMessage(data, fallback){
  if (data && data.error && typeof data.error.message === 'string') {
    return data.error.message;
  }
  if (data && typeof data.erro === 'string') {
    return data.erro;
  }
  if (data && typeof data.err === 'string') {
    return data.err;
  }
  return fallback;
}

function handleAuthenticationRequired(message){
  if (sessionRedirecting) return;
  sessionRedirecting = true;
  showToast(message, false, 3600);
  openAdminDialog({
    title:'Sessão encerrada',
    message,
    confirmLabel:'Ir para o login',
    cancelLabel:null,
    tone:'danger'
  }).finally(()=>{
    window.location.assign('admin.php');
  });
  window.setTimeout(()=>{
    window.location.assign('admin.php');
  }, 3600);
}

async function fetchJsonAdmin(url, options={}){
  let response;
  try {
    response = await fetch(url, options);
  } catch (error) {
    throw new AdminRequestError('Não foi possível conectar ao servidor.');
  }

  const contentType = response.headers.get('content-type') || '';
  let data = null;
  if (contentType.includes('application/json')) {
    try {
      data = await response.json();
    } catch (error) {
      throw new AdminRequestError('O servidor retornou uma resposta inválida.', response.status);
    }
  }

  const errorCode = data && data.error && typeof data.error.code === 'string'
    ? data.error.code
    : '';

  if (response.status === 401 || errorCode === 'authentication_required') {
    const message = getErrorMessage(
      data,
      'Sua sessão administrativa expirou. Entre novamente.'
    );
    handleAuthenticationRequired(message);
    throw new AdminAuthenticationRequiredError(message);
  }

  if (!data) {
    throw new AdminRequestError(
      'O servidor não retornou dados JSON.',
      response.status
    );
  }

  if (!response.ok) {
    throw new AdminRequestError(
      getErrorMessage(data, 'Não foi possível concluir a solicitação.'),
      response.status,
      data
    );
  }

  return data;
}

function abrirAba(id, moveFocus=false){
  const tabs = Array.from(document.querySelectorAll('[role="tab"][data-tab]'));
  const sections = Array.from(document.querySelectorAll('[role="tabpanel"]'));
  const selectedTab = tabs.find(tab=>tab.dataset.tab === id);
  const selectedSection = document.getElementById(id);

  if (!selectedTab || !selectedSection) return;

  tabs.forEach(tab=>{
    const active = tab === selectedTab;
    tab.classList.toggle('active', active);
    tab.setAttribute('aria-selected', active ? 'true' : 'false');
    tab.tabIndex = active ? 0 : -1;
  });
  sections.forEach(section=>{
    const active = section === selectedSection;
    section.classList.toggle('active', active);
    section.hidden = !active;
  });

  if (moveFocus) selectedTab.focus();
  if (id === 'davez') carregarDaVez(true);
}

function escapeHtml(str){
  return String(str)
    .replaceAll('&','&amp;')
    .replaceAll('<','&lt;')
    .replaceAll('>','&gt;')
    .replaceAll('"','&quot;')
    .replaceAll("'",'&#039;');
}

function showToast(msg, ok=true, duration=2600){
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.dataset.tone = ok ? 'success' : 'error';
  t.classList.add('show');
  window.clearTimeout(toastTimer);
  toastTimer = window.setTimeout(()=>t.classList.remove('show'), duration);
}

function closeAdminDialog(result){
  const layer = document.getElementById('adminDialogLayer');
  layer.hidden = true;
  if (dialogResolver) {
    const resolve = dialogResolver;
    dialogResolver = null;
    resolve(result);
  }
  if (dialogPreviousFocus && document.contains(dialogPreviousFocus)) {
    dialogPreviousFocus.focus();
  }
  dialogPreviousFocus = null;
}

function openAdminDialog({
  title,
  message,
  confirmLabel='Confirmar',
  cancelLabel='Cancelar',
  tone='default'
}){
  if (dialogResolver) closeAdminDialog(false);

  const layer = document.getElementById('adminDialogLayer');
  const dialog = document.getElementById('adminDialog');
  const confirmButton = document.getElementById('adminDialogConfirm');
  const cancelButton = document.getElementById('adminDialogCancel');
  dialogPreviousFocus = document.activeElement;

  document.getElementById('adminDialogTitle').textContent = title;
  document.getElementById('adminDialogMessage').textContent = message;
  confirmButton.textContent = confirmLabel;
  confirmButton.className = tone === 'danger' ? 'btn-danger' : 'btn-primary';
  cancelButton.hidden = cancelLabel === null;
  if (cancelLabel !== null) cancelButton.textContent = cancelLabel;
  layer.hidden = false;

  window.setTimeout(()=>confirmButton.focus(), 0);
  return new Promise(resolve=>{
    dialogResolver = resolve;
  });
}

function handleDialogKeydown(event){
  const layer = document.getElementById('adminDialogLayer');
  if (layer.hidden) return;

  if (event.key === 'Escape') {
    const cancelButton = document.getElementById('adminDialogCancel');
    if (!cancelButton.hidden) {
      event.preventDefault();
      closeAdminDialog(false);
    }
    return;
  }

  if (event.key !== 'Tab') return;
  const focusable = Array.from(
    document.getElementById('adminDialog').querySelectorAll('button:not([hidden]):not(:disabled)')
  );
  if (focusable.length === 0) return;
  const first = focusable[0];
  const last = focusable[focusable.length - 1];
  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault();
    last.focus();
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault();
    first.focus();
  }
}

function setButtonBusy(button, busy){
  if (!button) return;
  button.disabled = busy;
  button.setAttribute('aria-busy', busy ? 'true' : 'false');
}

function renderState(message, tone='empty'){
  const safeMessage = escapeHtml(message);
  const toneClass = tone === 'error' ? ' state-error' : '';
  return `<div class="state-row${toneClass}" data-state="${tone}">${safeMessage}</div>`;
}

function renderListState(message, tone='empty'){
  const safeMessage = escapeHtml(message);
  const toneClass = tone === 'error' ? ' state-error' : '';
  return `<li class="state-row${toneClass}" data-state="${tone}">${safeMessage}</li>`;
}

async function toggleChamada(){
  const button = document.getElementById('btnToggle');
  setButtonBusy(button, true);
  try {
    const resp = await fetchJsonAdmin("admin.php", {
      method:"POST",
      headers:{
        "Content-Type":"application/json",
        "X-CSRF-Token":CSRF_TOKEN
      },
      body:JSON.stringify({acao:"toggle_chamada"})
    });
    if (!resp.sucesso) {
      showToast(getErrorMessage(resp, "Falha ao alterar chamada."), false);
      return;
    }
    await carregar();
  } catch (error) {
    if (!(error instanceof AdminAuthenticationRequiredError)) {
      showToast(error.message || "Falha ao alterar chamada.", false);
    }
  } finally {
    setButtonBusy(button, false);
  }
}

async function limpar(){
  const confirmed = await openAdminDialog({
    title:'Limpar lista do ciclo?',
    message:'A lista atual será removida e um relatório será salvo no banco.',
    confirmLabel:'Limpar e salvar',
    cancelLabel:'Cancelar',
    tone:'danger'
  });
  if (!confirmed) return;

  const button = document.getElementById('btnClear');
  setButtonBusy(button, true);
  try {
    const data = await fetchJsonAdmin("admin.php", {
      method:"POST",
      headers:{
        "Content-Type":"application/json",
        "X-CSRF-Token":CSRF_TOKEN
      },
      body:JSON.stringify({acao:"limpar"})
    });
    if (!data.sucesso) {
      throw new AdminRequestError(getErrorMessage(data, "Falha ao salvar relatório."));
    }
    await carregarRelatorios();
    showToast("Relatório salvo e lista limpa.", true);
    await carregar();
  } catch (error) {
    if (!(error instanceof AdminAuthenticationRequiredError)) {
      showToast(error.message || "Falha ao salvar relatório.", false);
    }
  } finally {
    setButtonBusy(button, false);
  }
}

async function salvar(){
  const button = document.getElementById('btnSaveSettings');
  let f = new FormData();
  f.append("form_action", "save_settings");
  f.append("_csrf", CSRF_TOKEN);
  f.append("token", document.getElementById('token').value);
  f.append("lat", document.getElementById('lat').value);
  f.append("lng", document.getElementById('lng').value);
  f.append("raio", document.getElementById('raio').value);
  setButtonBusy(button, true);
  try {
    const resp = await fetchJsonAdmin("admin.php", {
      method:"POST",
      headers:{"X-CSRF-Token":CSRF_TOKEN},
      body:f
    });
    if (!resp.sucesso) {
      showToast(getErrorMessage(resp, "Configurações inválidas."), false);
      return;
    }
    showToast("Configurações salvas.", true);
    await carregar();
  } catch (error) {
    if (!(error instanceof AdminAuthenticationRequiredError)) {
      showToast(error.message || "Falha ao salvar configurações.", false);
    }
  } finally {
    setButtonBusy(button, false);
  }
}

async function toggleClose(button, id){
  const li = button.closest('li');
  setButtonBusy(button, true);
  try{
    const resp = await fetchJsonAdmin('admin.php', {
      method:'POST',
      headers:{
        'Content-Type':'application/json',
        'X-CSRF-Token':CSRF_TOKEN
      },
      body: JSON.stringify({acao:'toggle_close', id:id})
    });

    if (!resp.sucesso) {
      showToast(getErrorMessage(resp, "Falha ao atualizar."), false);
      await carregar();
      return;
    }

    if (Number(resp.is_closed || 0) === 1 && li) {
      showToast(
        resp.removidos_da_vez > 0
          ? "Fechado e removido da fila da vez."
          : "Fechado e enviado para o fim da fila.",
        true
      );
      await carregar();
      await carregarDaVez(true);
      return;
    }

    if (Number(resp.is_closed || 0) === 0) {
      showToast("Motoboy reaberto.", true);
    }

    await carregar();
    await carregarDaVez(true);

  } catch(e){
    if (!(e instanceof AdminAuthenticationRequiredError)) {
      showToast(e.message || "Falha ao atualizar.", false);
      await carregar();
    }
  } finally {
    setButtonBusy(button, false);
  }
}

async function toggleManualBox(){
  const box = document.getElementById('manualBox');
  const btn = document.getElementById('btnShowManual');
  const isHidden = box.classList.contains('hidden');

  if (isHidden){
    box.classList.remove('hidden');
    btn.textContent = "Fechar formulário";
    btn.setAttribute('aria-expanded', 'true');
    window.setTimeout(()=>document.getElementById('manualNome').focus(), 50);
    return;
  }

  box.classList.add('hidden');
  btn.textContent = "Adicionar manualmente";
  btn.setAttribute('aria-expanded', 'false');
}

async function addManual(){
  const manualNome = document.getElementById('manualNome');
  const manualObs = document.getElementById('manualObs');
  const button = document.getElementById('btnAddManual');
  const nome = (manualNome.value || '').trim();
  const obs  = (manualObs.value || '').trim();

  if (!nome){
    showToast("Digite o nome.", false);
    manualNome.focus();
    return;
  }

  const payload = {acao:'add_manual', nome, obs};
  setButtonBusy(button, true);
  try {
    const resp = await fetchJsonAdmin('admin.php', {
      method:'POST',
      headers:{
        'Content-Type':'application/json',
        'X-CSRF-Token':CSRF_TOKEN
      },
      body: JSON.stringify(payload)
    });

    if (!resp.sucesso){
      showToast(getErrorMessage(resp, "Não foi possível adicionar."), false);
      return;
    }

    manualNome.value = '';
    manualObs.value = '';
    showToast("Adicionado na lista.", true);
    toggleManualBox();
    await carregar();
  } catch (error) {
    if (!(error instanceof AdminAuthenticationRequiredError)) {
      showToast(error.message || "Não foi possível adicionar.", false);
    }
  } finally {
    setButtonBusy(button, false);
  }
}

function atualizarStatusChamada(dados){
  const aberta = Number(dados.chamada_aberta || 0) === 1;
  const btn = document.getElementById('btnToggle');
  const pill = document.getElementById('statusLista');

  btn.textContent = aberta ? "Fechar lista" : "Abrir lista";
  pill.textContent = aberta ? "Lista aberta" : "Lista fechada";
  pill.className = "status-pill " + (aberta ? "status-open" : "status-closed");
}

async function carregarMetrics(){
  const dash = document.getElementById('dash');
  dash.setAttribute('aria-busy', 'true');
  try{
    const m = await fetchJsonAdmin("admin.php?action=metrics");

    const badge = document.getElementById('mStatus');
    const aberta = Number(m.chamada_aberta || 0) === 1;
    badge.className = 'badge2 ' + (aberta ? 'ok' : 'no');
    badge.textContent = aberta ? 'ABERTA' : 'FECHADA';

    document.getElementById('mTotal').textContent = Number(m.total || 0);
    document.getElementById('mAbertos').textContent = Number(m.abertos || 0);
    document.getElementById('mFechados').textContent = Number(m.fechados || 0);

    let ultimo = '--:--';
    if (m.ultimo){
      const d = new Date(m.ultimo.replace(' ', 'T'));
      if (!isNaN(d)) ultimo = d.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});
    }
    document.getElementById('mUltimo').textContent = ultimo;

    const tm = (m.tempo_medio_min === null || typeof m.tempo_medio_min === 'undefined')
      ? '--'
      : (Number(m.tempo_medio_min) + ' min');
    document.getElementById('mTempoMedio').textContent = 'Tempo médio: ' + tm;
  } catch(e){
    if (!(e instanceof AdminAuthenticationRequiredError)) {
      const badge = document.getElementById('mStatus');
      badge.className = 'badge2 no';
      badge.textContent = 'ERRO';
      dash.dataset.state = 'error';
    }
  } finally {
    dash.setAttribute('aria-busy', 'false');
  }
}

async function carregar(){
  if (carregando || pausado) return;
  carregando = true;
  const lista = document.getElementById('lista');
  lista.setAttribute('aria-busy', 'true');
  try{
    const d = await fetchJsonAdmin("admin.php?action=dados");
    document.getElementById('token').value = d.token || '';
    document.getElementById('tokenDisplay').textContent = d.token || '----';
    document.getElementById('lat').value = d.lat_base || '';
    document.getElementById('lng').value = d.lng_base || '';
    document.getElementById('raio').value = d.raio || '';

    tokenCycleEndAt = d.token_cycle_end ? new Date(d.token_cycle_end.replace(' ', 'T')) : null;

    atualizarStatusChamada(d);
    carregarMetrics();

    const t = await fetchJsonAdmin("admin.php?action=lista");
    if (!Array.isArray(t) || t.length === 0) {
      lista.innerHTML = renderListState('Nenhum motoboy no ciclo atual.');
      syncOrderButtons(lista, '.queue-item');
      return;
    }

    lista.innerHTML = t.map(m=>{
      const dh = new Date(String(m.data_hora || '').replace(' ', 'T'));
      const ds = isNaN(dh) ? '' : dh.toLocaleString();
      const fechado = Number(m.is_closed || 0) === 1;
      const id = Number.parseInt(m.id || '0', 10);
      const nome = escapeHtml(m.nome || '');

      return `<li class="queue-item" data-id="${id}">
        <div class="queue-main">
          <span class="ordem">${m.ordem}º</span>
          <div class="item-text">
            <span class="nome ${fechado ? 'fechado' : ''}">${nome}</span>
            <small class="mini">${escapeHtml(ds)}</small>
          </div>
          <span class="badge ${fechado ? 'fechado' : 'aberto'}">${fechado ? 'FECHADO' : 'ABERTO'}</span>
        </div>
        <div class="item-actions">
          <div class="order-actions" aria-label="Reordenar ${nome}">
            <button type="button" class="order-btn" data-action="move-main" data-direction="-1"
              aria-label="Subir ${nome} na fila">↑ <span aria-hidden="true">Subir</span></button>
            <button type="button" class="order-btn" data-action="move-main" data-direction="1"
              aria-label="Descer ${nome} na fila">↓ <span aria-hidden="true">Descer</span></button>
          </div>
          <button type="button" class="mini-btn ${fechado ? 'reabrir' : 'fechar'}"
            data-action="toggle-close" data-id="${id}">${fechado ? 'Reabrir' : 'Fechar'}</button>
        </div>
      </li>`;
    }).join('');
    syncOrderButtons(lista, '.queue-item');
  } catch (error) {
    if (!(error instanceof AdminAuthenticationRequiredError)) {
      lista.innerHTML = renderListState(
        error.message || 'Não foi possível carregar a lista.',
        'error'
      );
    }
  } finally {
    carregando = false;
    lista.setAttribute('aria-busy', 'false');
  }
}

function syncOrderButtons(container, itemSelector){
  const items = Array.from(container.querySelectorAll(itemSelector));
  items.forEach((item, index)=>{
    const up = item.querySelector('[data-direction="-1"]');
    const down = item.querySelector('[data-direction="1"]');
    if (up) up.disabled = index === 0;
    if (down) down.disabled = index === items.length - 1;
  });
}

function moveItemInContainer(button, itemSelector, direction){
  const item = button.closest(itemSelector);
  if (!item || !item.parentElement) return false;
  const sibling = direction < 0 ? item.previousElementSibling : item.nextElementSibling;
  if (!sibling || !sibling.matches(itemSelector)) return false;

  if (direction < 0) {
    item.parentElement.insertBefore(item, sibling);
  } else {
    item.parentElement.insertBefore(sibling, item);
  }
  return true;
}

async function persistMainOrder(){
  const ordem = Array.from(document.querySelectorAll('#lista .queue-item'))
    .map(item=>Number.parseInt(item.dataset.id || '0', 10))
    .filter(id=>id > 0);
  const response = await fetchJsonAdmin('admin.php', {
    method:'POST',
    headers:{
      'Content-Type':'application/json',
      'X-CSRF-Token':CSRF_TOKEN
    },
    body:JSON.stringify({acao:'atualizar_ordem', ordem})
  });
  if (!response.sucesso) {
    throw new AdminRequestError(getErrorMessage(response, 'Falha ao atualizar a ordem.'));
  }
}

async function moveMainItem(button, direction){
  const lista = document.getElementById('lista');
  if (!moveItemInContainer(button, '.queue-item', direction)) return;
  syncOrderButtons(lista, '.queue-item');
  pausado = true;
  setButtonBusy(button, true);
  try {
    await persistMainOrder();
    showToast('Ordem de chegada atualizada.');
  } catch (error) {
    if (!(error instanceof AdminAuthenticationRequiredError)) {
      showToast(error.message || 'Falha ao atualizar a ordem.', false);
    }
  } finally {
    pausado = false;
    setButtonBusy(button, false);
    await carregar();
  }
}

function fmtHora(dt){
  try{
    const d = new Date(String(dt || '').replace(' ', 'T'));
    if (!isNaN(d)) return d.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});
  }catch(e){}
  return '';
}

async function sairParaEntrega(id){
  if (!id) return;
  try{
    const fd = new FormData();
    fd.append('id', id);

    const r = await fetchJsonAdmin(DV_SAIR_URL, {
      method:'POST',
      headers:{'X-CSRF-Token':CSRF_TOKEN},
      body: fd
    });

    if (!r.ok){
      showToast(getErrorMessage(r, "Falha ao marcar saída."), false);
    } else {
      showToast("Marcado como EM ENTREGA.", true);
    }
  } catch (error) {
    if (!(error instanceof AdminAuthenticationRequiredError)) {
      showToast(error.message || "Falha ao marcar saída.", false);
    }
  } finally {
    carregarDaVez(true);
  }
}

async function persistDaVezOrder(){
  const ordem = Array.from(document.querySelectorAll('#dvFila .dv-item'))
    .map(item=>Number.parseInt(item.dataset.id || '0', 10))
    .filter(id=>id > 0);
  const response = await fetchJsonAdmin(DV_REORDER_URL, {
    method:'POST',
    headers:{
      'Content-Type':'application/json',
      'X-CSRF-Token':CSRF_TOKEN
    },
    body:JSON.stringify({ordem})
  });
  if (!response.ok) {
    throw new AdminRequestError(getErrorMessage(response, 'Falha ao reordenar a fila Da vez.'));
  }
}

async function moveDaVezItem(button, direction){
  const fila = document.getElementById('dvFila');
  if (!moveItemInContainer(button, '.dv-item', direction)) return;
  syncOrderButtons(fila, '.dv-item');
  dvPausado = true;
  setButtonBusy(button, true);
  try {
    await persistDaVezOrder();
    showToast('Ordem da fila Da vez atualizada.');
  } catch (error) {
    if (!(error instanceof AdminAuthenticationRequiredError)) {
      showToast(error.message || 'Falha ao reordenar a fila Da vez.', false);
    }
  } finally {
    dvPausado = false;
    setButtonBusy(button, false);
    carregarDaVez(true);
  }
}

function initDaVezSortable(){
  const el = document.getElementById('dvFila');
  if (!el) return;

  if (dvSortable) {
    dvSortable.destroy();
    dvSortable = null;
  }

  if (!window.Sortable || typeof window.Sortable.create !== 'function') return;

  dvSortable = window.Sortable.create(el, {
    animation: 150,
    handle: '.dv-drag',
    filter: 'button',
    preventOnFilter: false,

    onStart(){
      dvPausado = true;
    },

    async onEnd(){
      try{
        await persistDaVezOrder();
        showToast('Ordem da fila Da vez atualizada.', true);
      } catch (error) {
        if (!(error instanceof AdminAuthenticationRequiredError)) {
          showToast(error.message || 'Falha ao reordenar a fila Da vez.', false);
        }
      } finally {
        dvPausado = false;
        carregarDaVez(true);
      }
    }
  });
}

async function carregarDaVez(force=false){
  if (dvCarregando || dvPausado) return;

  const now = Date.now();
  if (!force && now - dvLast < 3000) return;
  dvLast = now;

  dvCarregando = true;
  const filaBox = document.getElementById('dvFila');
  const entBox = document.getElementById('dvEntrega');
  filaBox.setAttribute('aria-busy', 'true');
  entBox.setAttribute('aria-busy', 'true');
  try{
    const data = await fetchJsonAdmin(DV_LIST_URL);

    if (!data.ok){
      document.getElementById('dvDaVezBox').innerHTML =
        `<span class="badge2 no">ERRO</span> <small class="mini">${
          escapeHtml(getErrorMessage(data, 'Falha ao carregar'))
        }</small>`;

      filaBox.innerHTML = renderState('Erro ao carregar a fila.', 'error');
      entBox.innerHTML = renderState('Erro ao carregar as entregas.', 'error');
      return;
    }

    const da = data.da_vez;
    if (da && da.nome){
      document.getElementById('dvDaVezBox').innerHTML =
        `<span class="badge2 ok">DA VEZ</span> <b>${escapeHtml(da.nome)}</b> <small class="mini">(${escapeHtml(da.client_id || '')})</small>`;
    } else {
      document.getElementById('dvDaVezBox').innerHTML =
        `<span class="badge2 no">SEM FILA</span> <small class="mini">Ninguém disponível agora.</small>`;
    }

    const fila = Array.isArray(data.fila) ? data.fila : [];
    const naFila = fila.filter(x => String(x.status || '') === 'na_fila');
    const emEnt = fila.filter(x => String(x.status || '') === 'em_entrega');

    if (naFila.length === 0){
      filaBox.innerHTML = renderState('Fila vazia.');
      if (dvSortable) {
        dvSortable.destroy();
        dvSortable = null;
      }
    } else {
      let html = '';

      naFila.forEach((x, i) => {
        const isDaVez = (i === 0);
        const cid = String(x.client_id || '');
        const rowId = parseInt(x.id || '0', 10);
        const ordemTxt = (typeof x.ordem !== 'undefined' && x.ordem !== null) ? x.ordem : '-';
        const nome = escapeHtml(x.nome || '');

        html += `
          <div class="dv-item" data-id="${rowId}" role="listitem">
            <div class="item-copy">
              <span class="dv-drag" title="Arrastar" aria-hidden="true">⋮⋮</span>
              <div class="item-text">
                <b>${isDaVez ? '🥇 ' : ''}${nome}</b>
                <div class="mini">
                  Posição: ${i + 1}
                  | Ordem: ${escapeHtml(String(ordemTxt))}
                  | Entrou: ${escapeHtml(fmtHora(x.entered_at))}
                  | CID: ${escapeHtml(cid)}
                </div>
              </div>
            </div>
            <div class="item-actions">
              <div class="order-actions" aria-label="Reordenar ${nome}">
                <button type="button" class="order-btn" data-action="move-davez" data-direction="-1"
                  aria-label="Subir ${nome} na fila">↑ <span aria-hidden="true">Subir</span></button>
                <button type="button" class="order-btn" data-action="move-davez" data-direction="1"
                  aria-label="Descer ${nome} na fila">↓ <span aria-hidden="true">Descer</span></button>
              </div>
              ${isDaVez ? `<button type="button" class="mini-btn btn-warning" data-action="deliver" data-id="${rowId}">Saiu para entrega</button>` : ''}
            </div>
          </div>
        `;
      });

      filaBox.innerHTML = html;
      syncOrderButtons(filaBox, '.dv-item');
      initDaVezSortable();
    }

    if (emEnt.length === 0){
      entBox.innerHTML = renderState('Ninguém em entrega.');
    } else {
      entBox.innerHTML = emEnt.map((x) => {
        return `
          <div class="delivery-item" role="listitem">
            <div class="item-text">
              <b>${escapeHtml(x.nome || '')}</b>
              <div class="mini">Saiu: ${escapeHtml(fmtHora(x.last_action_at || x.entered_at))} | CID: ${escapeHtml(x.client_id || '')}</div>
            </div>
          </div>
        `;
      }).join('');
    }
  } catch (error) {
    if (!(error instanceof AdminAuthenticationRequiredError)) {
      const message = error.message || 'Falha ao carregar a fila Da vez.';
      document.getElementById('dvDaVezBox').innerHTML =
        `<span class="badge2 no">ERRO</span> <small class="mini">${escapeHtml(message)}</small>`;
      filaBox.innerHTML = renderState(message, 'error');
      entBox.innerHTML = renderState('Não foi possível carregar as entregas.', 'error');
    }
  } finally {
    dvCarregando = false;
    filaBox.setAttribute('aria-busy', 'false');
    entBox.setAttribute('aria-busy', 'false');
  }
}

setInterval(()=>{
  const sec = document.getElementById('davez');
  if (sec && sec.classList.contains('active')) carregarDaVez(false);
}, 8000);

async function carregarRelatorios(){
  const box = document.getElementById('lastReportBox');
  box.setAttribute('aria-busy', 'true');
  try {
    const lista = await fetchJsonAdmin("admin.php?action=listar_relatorios");
    if (!Array.isArray(lista) || lista.length === 0) {
      box.innerHTML = renderState("Nenhum relatório salvo ainda.");
      return;
    }

    box.innerHTML = `
      <div class="report-list">
        ${lista.map(r => `
          <article class="report-item">
            <div class="report-copy">
              <b>Relatório #${escapeHtml(r.id)}</b>
              <div>${escapeHtml(r.periodo_inicio)} → ${escapeHtml(r.periodo_fim)}</div>
              <div class="mini">Total: ${escapeHtml(r.total_checkins)} | Únicos: ${escapeHtml(r.motoboys_unicos)} | Fechados: ${escapeHtml(r.total_fechados)}</div>
            </div>
            <div class="item-actions">
              <button type="button" class="mini-btn btn-primary" data-action="view-report" data-id="${escapeHtml(r.id)}">Ver</button>
              <button type="button" class="mini-btn btn-danger" data-action="delete-report" data-id="${escapeHtml(r.id)}">Apagar</button>
            </div>
          </article>
        `).join('')}
      </div>
    `;
  } catch (error) {
    if (!(error instanceof AdminAuthenticationRequiredError)) {
      box.innerHTML = renderState(
        error.message || 'Não foi possível carregar os relatórios.',
        'error'
      );
    }
  } finally {
    box.setAttribute('aria-busy', 'false');
  }
}

async function abrirRelatorio(id){
  const box = document.getElementById('lastReportBox');
  box.setAttribute('aria-busy', 'true');
  try {
    const data = await fetchJsonAdmin("admin.php?action=ver_relatorio&id=" + encodeURIComponent(id));
    if (data.erro) throw new AdminRequestError(data.erro);

    const meta = data.meta;
    const items = (data.payload && Array.isArray(data.payload.items))
      ? data.payload.items
      : [];
    const rows = items.length === 0
      ? '<tr><td colspan="5">Este relatório não possui itens.</td></tr>'
      : items.map(it => {
          const fechado = Number(it.is_closed || 0) === 1;
          return `
            <tr>
              <td>${escapeHtml(it.ordem ?? '')}º</td>
              <td class="${fechado ? 'is-closed' : ''}">${escapeHtml(it.nome ?? '')}</td>
              <td>${escapeHtml(it.data_hora ?? '')}</td>
              <td>${fechado ? 'FECHADO' : 'ABERTO'}</td>
              <td>${escapeHtml(it.closed_at ?? '-')}</td>
            </tr>
          `;
        }).join('');

    box.innerHTML = `
      <div class="toolbar">
        <div>
          <b>Relatório #${escapeHtml(meta.id)}</b>
          <div class="mini">${escapeHtml(meta.periodo_inicio)} → ${escapeHtml(meta.periodo_fim)}</div>
          <div class="mini">Total: ${escapeHtml(meta.total_checkins)} | Únicos: ${escapeHtml(meta.motoboys_unicos)} | Fechados: ${escapeHtml(meta.total_fechados)}</div>
        </div>
        <button type="button" class="mini-btn btn-secondary" data-action="back-reports">Voltar</button>
      </div>
      <div class="table-scroll" tabindex="0" role="region"
        aria-label="Itens do relatório ${escapeHtml(meta.id)}">
        <table class="report-table">
          <caption>Entradas registradas no período do relatório.</caption>
          <thead>
            <tr>
              <th scope="col">Ordem</th>
              <th scope="col">Nome</th>
              <th scope="col">Check-in</th>
              <th scope="col">Status</th>
              <th scope="col">Fechado em</th>
            </tr>
          </thead>
          <tbody>${rows}</tbody>
        </table>
      </div>
    `;
  } catch (error) {
    if (!(error instanceof AdminAuthenticationRequiredError)) {
      showToast(error.message || 'Não foi possível abrir o relatório.', false);
    }
  } finally {
    box.setAttribute('aria-busy', 'false');
  }
}

async function apagarRelatorio(id){
  const confirmed = await openAdminDialog({
    title:`Apagar relatório #${id}?`,
    message:'A lista atual não será alterada. O relatório salvo será removido permanentemente.',
    confirmLabel:'Apagar relatório',
    cancelLabel:'Cancelar',
    tone:'danger'
  });
  if (!confirmed) return;

  try {
    const resp = await fetchJsonAdmin("admin.php", {
      method:"POST",
      headers:{
        "Content-Type":"application/json",
        "X-CSRF-Token":CSRF_TOKEN
      },
      body:JSON.stringify({acao:"apagar_relatorio", id})
    });
    if (!resp.sucesso) {
      throw new AdminRequestError(getErrorMessage(resp, "Não foi possível apagar."));
    }
    showToast('Relatório apagado.');
    await carregarRelatorios();
  } catch (error) {
    if (!(error instanceof AdminAuthenticationRequiredError)) {
      showToast(error.message || "Não foi possível apagar.", false);
    }
  }
}

function initMainSortable(){
  const list = document.getElementById('lista');
  if (!list || !window.Sortable || typeof window.Sortable.create !== 'function') return;
  sortable = window.Sortable.create(list, {
    filter:'button',
    preventOnFilter:false,
    animation:150,
    onStart(){ pausado = true; },
    async onEnd(){
      try {
        await persistMainOrder();
        showToast('Ordem de chegada atualizada.');
      } catch (error) {
        if (!(error instanceof AdminAuthenticationRequiredError)) {
          showToast(error.message || 'Falha ao atualizar a ordem.', false);
        }
      } finally {
        pausado = false;
        carregar();
      }
    }
  });
}

function contadorToken(){
  if (!tokenCycleEndAt || isNaN(tokenCycleEndAt.getTime())) {
    document.getElementById('contador').textContent = '--:--:--';
    return;
  }

  const agora = new Date();
  let diff = Math.floor((tokenCycleEndAt.getTime() - agora.getTime()) / 1000);

  if (diff < 0) diff = 0;

  const dias = Math.floor(diff / 86400);
  const resto = diff % 86400;
  const h = String(Math.floor(resto / 3600)).padStart(2,'0');
  const m = String(Math.floor((resto % 3600) / 60)).padStart(2,'0');
  const s = String(resto % 60).padStart(2,'0');

  document.getElementById('contador').textContent =
    (dias > 0 ? `${dias}d ` : '') + `${h}:${m}:${s}`;
}

document.querySelector('.tabs').addEventListener('click', event=>{
  const tab = event.target.closest('[role="tab"][data-tab]');
  if (tab) abrirAba(tab.dataset.tab, false);
});

document.querySelector('.tabs').addEventListener('keydown', event=>{
  if (!['ArrowLeft','ArrowRight','Home','End'].includes(event.key)) return;
  const tabs = Array.from(document.querySelectorAll('[role="tab"][data-tab]'));
  const current = tabs.indexOf(event.target.closest('[role="tab"]'));
  if (current < 0) return;
  event.preventDefault();
  let next = current;
  if (event.key === 'ArrowRight') next = (current + 1) % tabs.length;
  if (event.key === 'ArrowLeft') next = (current - 1 + tabs.length) % tabs.length;
  if (event.key === 'Home') next = 0;
  if (event.key === 'End') next = tabs.length - 1;
  abrirAba(tabs[next].dataset.tab, true);
});

document.getElementById('btnToggle').addEventListener('click', toggleChamada);
document.getElementById('btnShowManual').addEventListener('click', toggleManualBox);
document.getElementById('btnClear').addEventListener('click', limpar);
document.getElementById('btnRefreshDavez').addEventListener('click', ()=>carregarDaVez(true));
document.getElementById('manualForm').addEventListener('submit', event=>{
  event.preventDefault();
  addManual();
});
document.getElementById('configForm').addEventListener('submit', event=>{
  event.preventDefault();
  salvar();
});

document.getElementById('lista').addEventListener('click', event=>{
  const button = event.target.closest('button[data-action]');
  if (!button) return;
  const action = button.dataset.action;
  if (action === 'toggle-close') {
    toggleClose(button, Number.parseInt(button.dataset.id || '0', 10));
  }
  if (action === 'move-main') {
    moveMainItem(button, Number.parseInt(button.dataset.direction || '0', 10));
  }
});

document.getElementById('dvFila').addEventListener('click', event=>{
  const button = event.target.closest('button[data-action]');
  if (!button) return;
  if (button.dataset.action === 'deliver') {
    sairParaEntrega(Number.parseInt(button.dataset.id || '0', 10));
  }
  if (button.dataset.action === 'move-davez') {
    moveDaVezItem(button, Number.parseInt(button.dataset.direction || '0', 10));
  }
});

document.getElementById('lastReportBox').addEventListener('click', event=>{
  const button = event.target.closest('button[data-action]');
  if (!button) return;
  const id = Number.parseInt(button.dataset.id || '0', 10);
  if (button.dataset.action === 'view-report') abrirRelatorio(id);
  if (button.dataset.action === 'delete-report') apagarRelatorio(id);
  if (button.dataset.action === 'back-reports') carregarRelatorios();
});

document.getElementById('adminDialogConfirm').addEventListener('click', ()=>closeAdminDialog(true));
document.getElementById('adminDialogCancel').addEventListener('click', ()=>closeAdminDialog(false));
document.getElementById('adminDialogLayer').addEventListener('click', event=>{
  if (event.target === event.currentTarget) {
    const cancelButton = document.getElementById('adminDialogCancel');
    if (!cancelButton.hidden) closeAdminDialog(false);
  }
});
document.addEventListener('keydown', handleDialogKeydown);

setInterval(carregar, 12000);
setInterval(contadorToken, 1000);
initMainSortable();
carregar();
contadorToken();
carregarRelatorios();
</script>
<div class="system-signature">
YD 808 • CORE v1.5
</div>
</body>
</html>
