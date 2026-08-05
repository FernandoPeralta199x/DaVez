<?php
require_once __DIR__ . '/src/Security/Bootstrap.php';
require_once __DIR__ . '/src/Domain/OperationalCycle.php';
require_once __DIR__ . '/src/Domain/OperationalContext.php';
require_once __DIR__ . '/src/Domain/QueueStateChanged.php';
require_once __DIR__ . '/src/Domain/QueueReorder.php';
require_once __DIR__ . '/src/Domain/ReportSnapshot.php';
require_once __DIR__ . '/src/Domain/DeliveryRanking.php';
require_once __DIR__ . '/src/Application/Ranking/RankingQuery.php';
require_once __DIR__ . '/src/Application/Reports/ReportListQuery.php';
require_once __DIR__ . '/src/Database/bootstrap.php';
require_once __DIR__ . '/log.php';
davez_install_safe_exception_handler();

$operationalCycle = \DaVez\Domain\OperationalCycle::fromEnvironment();
date_default_timezone_set($operationalCycle->timezone()->getName());
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
    . 'font:16px system-ui,sans-serif;background:radial-gradient(circle at 20% 10%,#163a80,#040914 46%);color:#f4f7fb}'
    . 'main{width:min(92vw,420px);padding:32px;border:1px solid #263142;'
    . 'border-radius:24px;background:linear-gradient(180deg,#0b1930,#07111f);box-shadow:0 28px 80px rgba(0,0,0,.48)}'
    . 'nav{display:flex;margin-bottom:22px}'
    . '.brand-strip{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:24px}'
    . '.brand-strip img{width:132px;height:auto;padding:10px 14px;border-radius:14px;background:#f7f9ff;box-shadow:0 10px 26px rgba(0,0,0,.24)}'
    . '.secure-indicator{display:inline-flex;align-items:center;gap:7px;padding:7px 10px;border:1px solid rgba(92,139,255,.24);border-radius:999px;color:#a9bad7;background:rgba(27,51,91,.45);font:700 10px ui-monospace,SFMono-Regular,Consolas,monospace;letter-spacing:.08em;text-transform:uppercase;white-space:nowrap}'
    . '.secure-indicator::before{width:7px;height:7px;border-radius:50%;background:#20d68a;box-shadow:0 0 0 4px rgba(32,214,138,.1),0 0 12px rgba(32,214,138,.55);content:""}'
    . '.login-kicker{margin:0 0 8px;color:#75a0ff;font:800 11px ui-monospace,SFMono-Regular,Consolas,monospace;letter-spacing:.14em;text-transform:uppercase}'
    . 'h1{margin:0 0 10px;font-size:clamp(28px,7vw,36px);line-height:1.08;letter-spacing:-.035em}'
    . '.back-home{display:inline-flex;align-items:center;gap:8px;min-height:44px;'
    . 'padding:5px 12px 5px 5px;border:1px solid rgba(125,151,190,.3);'
    . 'border-radius:999px;color:#c7d0dc;background:#111824;'
    . 'box-shadow:inset 0 1px 0 rgba(255,255,255,.05);font-size:13px;'
    . 'font-weight:750;text-decoration:none;transform:translateY(0);'
    . 'transition:color 260ms cubic-bezier(.32,.72,0,1),'
    . 'border-color 260ms cubic-bezier(.32,.72,0,1),'
    . 'background 260ms cubic-bezier(.32,.72,0,1),'
    . 'transform 260ms cubic-bezier(.32,.72,0,1)}'
    . '.back-home-icon{display:inline-grid;place-items:center;width:32px;'
    . 'height:32px;border-radius:50%;color:#ffd1a3;background:#1b2a41;'
    . 'font-size:16px;transform:translateX(0);'
    . 'transition:transform 260ms cubic-bezier(.32,.72,0,1)}'
    . '.back-home:hover{border-color:#6f87ad;color:#fff;background:#172235;'
    . 'transform:translateY(-2px)}'
    . '.back-home:hover .back-home-icon{transform:translateX(-2px)}'
    . '.back-home:active{transform:translateY(0) scale(.98)}'
    . '.back-home:focus-visible{outline:3px solid rgba(255,138,31,.36);'
    . 'outline-offset:3px}'
    . 'label{display:block;margin:18px 0 6px;color:#c7d0dc}'
    . 'input{box-sizing:border-box;width:100%;padding:13px;border:1px solid '
    . '#38465a;border-radius:10px;background:#111824;color:#fff}'
    . 'button{width:100%;margin-top:22px;padding:13px;border:0;border-radius:10px;'
    . 'background:linear-gradient(100deg,#2b65e8,#477fff);color:#fff;box-shadow:0 12px 28px rgba(47,105,240,.3);font-weight:800;cursor:pointer}'
    . '.error{padding:12px;border-radius:10px;background:#421d27;color:#ffd8df}'
    . 'small{color:#96a0ae}'
    . '@media(max-width:420px){.brand-strip{align-items:flex-start;flex-direction:column}.brand-strip img{width:118px}}'
    . '@media(prefers-reduced-motion:reduce){.back-home,.back-home-icon{'
    . 'transition-duration:.01ms}}'
    . '@media(forced-colors:active){.back-home,.back-home-icon{'
    . 'border:1px solid CanvasText}}</style></head><body><main>'
    . '<nav aria-label="Navegação do acesso administrativo">'
    . '<a class="back-home" href="/" aria-label="Voltar para a tela inicial">'
    . '<span class="back-home-icon" aria-hidden="true">←</span>'
    . '<span>Voltar ao início</span></a></nav>'
    . '<div class="brand-strip"><img src="img/logo.png" alt="DaVez">'
    . '<span class="secure-indicator">Secure console</span></div>'
    . '<p class="login-kicker">Console operacional</p>'
    . '<h1>Administração DaVez</h1><small>Sessão protegida, temporária e monitorada.</small>'
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
davez_configure_operational_database_timezone($conn, $operationalCycle);
$operationalContext = new \DaVez\Domain\OperationalContext(
  $operationalCycle
);
$operationalStart = $operationalContext->startSql();
$operationalEnd = $operationalContext->endSql();
$operationalDate = $operationalContext->date();
$operationalCycleLabel = $operationalCycle->startTimeLabel();
$adminCanViewLogs = davez_admin_can_view_logs();

/* ===== configurações operacionais somente leitura ===== */
$settingsResult = $conn->query(
  "SELECT chamada_aberta, chamada_inicio, chamada_fim,
          lat_base, lng_base, raio
   FROM settings
   WHERE id=1
   LIMIT 1"
);
$s = $settingsResult ? $settingsResult->fetch_assoc() : null;

if (!$s) {
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

function admin_query_positive_int(
  array $input,
  string $key,
  int $default,
  int $maximum
): int {
  $raw = $input[$key] ?? null;
  if ($raw === null || $raw === '') {
    return $default;
  }
  if (!is_string($raw) || !ctype_digit($raw)) {
    throw new InvalidArgumentException('Parâmetro numérico inválido.');
  }
  $value = (int) $raw;
  if ($value < 1 || $value > $maximum) {
    throw new InvalidArgumentException('Parâmetro numérico fora do limite.');
  }
  return $value;
}

function admin_query_date(array $input, string $key): string {
  $raw = $input[$key] ?? null;
  if ($raw === null || $raw === '') {
    return '';
  }
  if (!is_string($raw)) {
    throw new InvalidArgumentException('Data inválida.');
  }
  $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
  if ($date === false || $date->format('Y-m-d') !== $raw) {
    throw new InvalidArgumentException('Data inválida.');
  }
  return $raw;
}

/* ===== actions ===== */
$action = is_string($_GET['action'] ?? null) ? $_GET['action'] : '';
$readActions = [
  'dados',
  'metrics',
  'lista',
  'listar_relatorios',
  'ver_relatorio',
  'logs',
  'ranking',
  'server_time'
];

if ($action !== '') {
  davez_require_http_method('GET');

  if (!in_array($action, $readActions, true)) {
    json_out(['erro' => 'Ação de leitura inválida.'], 400);
  }

  $allowedReadKeys = ['action'];
  if ($action === 'ver_relatorio') {
    $allowedReadKeys = ['action', 'id'];
  } elseif ($action === 'ranking') {
    $allowedReadKeys = [
      'action', 'periodo', 'date_from', 'date_to', 'page', 'per_page'
    ];
  } elseif ($action === 'listar_relatorios') {
    $allowedReadKeys = [
      'action', 'date_from', 'date_to', 'page', 'per_page'
    ];
  }

  try {
    davez_assert_allowed_input_keys($_GET, $allowedReadKeys);
  } catch (InvalidArgumentException $exception) {
    json_out(['erro' => 'Parâmetros de leitura inválidos.'], 400);
  }
}

if ($action === "server_time") {
  $serverNow = $operationalContext->reference();
  json_out([
    'ok' => true,
    'epoch_ms' => $serverNow->getTimestamp() * 1000,
    'iso' => $serverNow->format(DATE_ATOM),
    'timezone' => $operationalCycle->timezone()->getName(),
    'operational_date' => $operationalDate,
    'cycle_start' => $operationalCycleLabel,
  ]);
}

if ($action === "dados") {
  $settingsData = $conn->query(
    "SELECT chamada_aberta, chamada_inicio, chamada_fim,
            lat_base, lng_base, raio
     FROM settings
     WHERE id=1
     LIMIT 1"
  );
  $settingsRow = $settingsData
    ? $settingsData->fetch_assoc()
    : null;

  if (!$settingsRow) {
    json_out(['erro'=>'Configurações indisponíveis.'], 500);
  }

  json_out($settingsRow);
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
  try {
    $page = admin_query_positive_int($_GET, 'page', 1, 1000000);
    $perPage = admin_query_positive_int($_GET, 'per_page', 15, 50);
    $dateFrom = admin_query_date($_GET, 'date_from');
    $dateTo = admin_query_date($_GET, 'date_to');

    $reports = new \DaVez\Application\Reports\ReportListQuery($conn);
    $result = $reports->fetchPage(
      $dateFrom,
      $dateTo,
      $page,
      $perPage
    );
  } catch (InvalidArgumentException $exception) {
    json_out(['erro' => $exception->getMessage()], 400);
  } catch (RuntimeException $exception) {
    json_out(['erro' => 'Relatórios indisponíveis.'], 500);
  }

  json_out(array_merge(['ok' => true], $result));
}

if ($action === "logs") {
  // Apenas o dono (papel admin) enxerga os logs. Operadores/clientes não.
  if (!$adminCanViewLogs) {
    davez_send_error(
      'forbidden',
      'Este usuário não tem acesso aos logs.',
      403
    );
  }

  // Somente o log de eventos privado, já sanitizado. Nunca o log legado com
  // dados pessoais nem o error_log bruto do PHP.
  json_out([
    'ok' => true,
    'eventos' => read_recent_log_events(100),
  ]);
}

if ($action === "ranking") {
  $periodo = is_string($_GET['periodo'] ?? null) ? $_GET['periodo'] : 'dia';

  try {
    $page = admin_query_positive_int($_GET, 'page', 1, 1000000);
    $perPage = admin_query_positive_int($_GET, 'per_page', 25, 100);
    $dateFrom = admin_query_date($_GET, 'date_from');
    $dateTo = admin_query_date($_GET, 'date_to');

    if ($dateFrom !== '' || $dateTo !== '') {
      if ($dateFrom === '' || $dateTo === '') {
        throw new InvalidArgumentException(
          'Informe data inicial e final para o intervalo personalizado.'
        );
      }
      $periodo = 'custom';
      $bounds = \DaVez\Domain\DeliveryRanking::customBounds(
        $dateFrom,
        $dateTo,
        366
      );
      $previous = \DaVez\Domain\DeliveryRanking::previousCustomBounds(
        $dateFrom,
        $dateTo,
        366
      );
    } else {
      $bounds = \DaVez\Domain\DeliveryRanking::periodBounds(
        $periodo,
        $operationalDate
      );
      $previous = \DaVez\Domain\DeliveryRanking::previousBounds(
        $periodo,
        $operationalDate
      );
    }

    $rankingQuery = new \DaVez\Application\Ranking\RankingQuery($conn);
    $rankingResult = $rankingQuery->fetchPage(
      $bounds,
      $previous,
      $page,
      $perPage
    );
  } catch (InvalidArgumentException $exception) {
    json_out(['erro' => $exception->getMessage()], 400);
  } catch (RuntimeException $exception) {
    json_out(['erro' => 'Ranking temporariamente indisponível.'], 500);
  }

  json_out([
    'ok' => true,
    'periodo' => $periodo,
    'inicio' => $bounds['start'],
    'fim' => $bounds['end'],
    'page' => $rankingResult['page'],
    'per_page' => $rankingResult['per_page'],
    'total' => $rankingResult['total'],
    'total_pages' => $rankingResult['total_pages'],
    'ranking' => $rankingResult['ranking'],
  ]);
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

  // Ranking do período do relatório, derivado do log durável de entregas.
  // Cada relatório cobre exatamente um ciclo operacional, então a data
  // operacional do ciclo é a de periodo_inicio (evita ambiguidade de fuso,
  // igual à aba Ranking, que agrega por operational_date).
  $ranking = [];
  $rankStmt = $conn->prepare(
    "SELECT nome,
            COUNT(*) AS entregas,
            COUNT(DISTINCT operational_date) AS dias_ativos,
            ROUND(AVG(queue_wait_seconds)) AS espera_media_seg
     FROM delivery_events
     WHERE operational_date = DATE(?)
     GROUP BY nome
     ORDER BY entregas DESC, nome ASC
     LIMIT 200"
  );

  if ($rankStmt) {
    $rankStmt->bind_param('s', $row['periodo_inicio']);

    if ($rankStmt->execute()) {
      $rankResult = $rankStmt->get_result();
      $posicao = 1;
      while ($rankRow = $rankResult->fetch_assoc()) {
        $entregas = (int) $rankRow['entregas'];
        $diasAtivos = (int) $rankRow['dias_ativos'];
        $ranking[] = [
          'posicao' => $posicao,
          'nome' => (string) $rankRow['nome'],
          'entregas' => $entregas,
          'dias_ativos' => $diasAtivos,
          'espera_media_seg' => $rankRow['espera_media_seg'] === null
            ? null
            : (int) $rankRow['espera_media_seg'],
          'score' => \DaVez\Domain\DeliveryRanking::score(
            $entregas,
            $diasAtivos
          ),
        ];
        $posicao++;
      }
    }

    $rankStmt->close();
  }

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
    'payload' => $payload,
    'ranking' => $ranking,
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
    'issue_checkin_ticket' => ['acao', '_csrf'],
    'issue_recovery_ticket' => ['acao', 'id', '_csrf'],
    'ticket_status' => ['acao', 'access_code', '_csrf'],
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

  if ($acao === 'ticket_status') {
    try {
      $accessCode = davez_input_string(
        $input,
        'access_code',
        8,
        12
      );
      $codeStatus = davez_public_identity_store($conn)->findDailyCodeStatus(
        davez_public_ticket_hash($accessCode),
        $operationalDate,
        $operationalContext->reference()
      );
    } catch (InvalidArgumentException $exception) {
      json_out([
        'sucesso' => false,
        'erro' => 'Código individual inválido.'
      ], 400);
    } catch (RuntimeException $exception) {
      json_out([
        'sucesso' => false,
        'erro' => 'Estado do código temporariamente indisponível.'
      ], 503);
    }

    if ($codeStatus === null) {
      json_out([
        'sucesso' => false,
        'erro' => 'Código individual não encontrado no ciclo atual.'
      ], 404);
    }

    json_out([
      'sucesso' => true,
      'purpose' => 'daily',
      'ticket_state' => $codeStatus['state'],
      'activated' => $codeStatus['activated'],
      'expires_at' => $codeStatus['expires_at']->format(DATE_ATOM),
    ]);
  }

  if ($acao === 'issue_checkin_ticket') {
    // Código diário reutilizável: válido até a virada do ciclo configurado, ativado
    // no primeiro check-in e reutilizável para re-entrada e recuperação.
    $issuedAt = $operationalContext->reference();
    $expiresAt = $operationalContext->end();

    try {
      $accessCode = davez_public_ticket_code();
      davez_public_identity_store($conn)->issueDailyCode(
        davez_public_ticket_hash($accessCode),
        $operationalDate,
        null,
        $issuedAt,
        $expiresAt
      );
    } catch (InvalidArgumentException $exception) {
      json_out([
        'sucesso' => false,
        'erro' => 'Não foi possível emitir o código individual.'
      ], 400);
    } catch (RuntimeException $exception) {
      json_out([
        'sucesso' => false,
        'erro' => 'Emissão indisponível. Confira schema e configuração.'
      ], 503);
    }

    json_out([
      'sucesso' => true,
      'purpose' => 'daily',
      'access_code' => $accessCode,
      'expires_at' => $expiresAt->format(DATE_ATOM),
      'aviso' => 'Vale o dia todo: o mesmo código serve para check-in, re-entrada e recuperação.'
    ]);
  }

  if ($acao === 'issue_recovery_ticket') {
    // Reemite o código diário de um check-in existente (motoboy perdeu o código).
    try {
      $checkinId = davez_input_integer(
        $input,
        'id',
        1,
        PHP_INT_MAX
      );
    } catch (InvalidArgumentException $exception) {
      json_out([
        'sucesso' => false,
        'erro' => 'Check-in inválido para recuperação.'
      ], 400);
    }

    $target = $conn->prepare(
      "SELECT nome
       FROM checkins
       WHERE id=?
         AND operational_date=?
         AND COALESCE(is_closed, 0) = 0
       LIMIT 1"
    );

    if (!$target) {
      json_out([
        'sucesso' => false,
        'erro' => 'Check-in indisponível para recuperação.'
      ], 500);
    }

    $target->bind_param(
      'is',
      $checkinId,
      $operationalDate
    );

    if (!$target->execute()) {
      $target->close();
      json_out([
        'sucesso' => false,
        'erro' => 'Check-in indisponível para recuperação.'
      ], 500);
    }

    $targetName = null;
    $target->bind_result($targetName);
    $targetExists = $target->fetch();
    $target->close();

    if (!$targetExists) {
      json_out([
        'sucesso' => false,
        'erro' => 'Check-in não encontrado (ou já encerrado) no ciclo atual.'
      ], 404);
    }

    $issuedAt = $operationalContext->reference();
    $expiresAt = $operationalContext->end();

    try {
      $store = davez_public_identity_store($conn);
      $accessCode = davez_public_ticket_code();
      $newHash = davez_public_ticket_hash($accessCode);

      if (
        !$store->rotateDailyCodeHash(
          (int) $checkinId,
          $operationalDate,
          $newHash,
          $issuedAt
        )
      ) {
        $store->issueActivatedDailyCode(
          $newHash,
          (int) $checkinId,
          $operationalDate,
          (string) $targetName,
          $issuedAt,
          $expiresAt
        );
      }
    } catch (InvalidArgumentException $exception) {
      json_out([
        'sucesso' => false,
        'erro' => 'Não foi possível emitir o código individual.'
      ], 400);
    } catch (RuntimeException $exception) {
      json_out([
        'sucesso' => false,
        'erro' => 'Emissão indisponível. Confira schema e configuração.'
      ], 503);
    }

    json_out([
      'sucesso' => true,
      'purpose' => 'recovery',
      'access_code' => $accessCode,
      'expires_at' => $expiresAt->format(DATE_ATOM),
      'aviso' => 'Novo código do dia para este motoboy. O código anterior deixa de valer.'
    ]);
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
          $operationalEnd,
          $operationalDate
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

          $identityTables = [
            'fila_da_vez',
            'public_sessions',
            'admission_tickets',
            'daily_access_codes',
          ];

          foreach ($identityTables as $identityTable) {
            $dateColumn = $identityTable === 'fila_da_vez'
              ? 'dia'
              : 'operational_date';
            $identityDelete = $conn->prepare(
              "DELETE FROM {$identityTable}
               WHERE {$dateColumn}=?"
            );

            if (!$identityDelete) {
              throw new RuntimeException(
                'Identidade pública indisponível para limpeza.'
              );
            }

            $identityDelete->bind_param(
              's',
              $operationalDate
            );

            if (!$identityDelete->execute()) {
              $identityDelete->close();
              throw new RuntimeException(
                'Identidade pública indisponível para limpeza.'
              );
            }
            $identityDelete->close();
          }

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
    $publicIdentityStore = davez_public_identity_store($conn);
    $revokedAt = $operationalContext->reference();

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
          $publicIdentityStore,
          $revokedAt,
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
          $delete = $conn->prepare(
            "DELETE FROM fila_da_vez
             WHERE dia=?
               AND (
                 checkin_id=?
                 OR (
                   checkin_id IS NULL
                   AND (
                     client_id=?
                     OR LOWER(TRIM(nome))=LOWER(TRIM(?))
                   )
                 )
               )"
          );

          if (!$delete) {
            throw new RuntimeException(
              'Fila DaVez indisponível para atualização.'
            );
          }
          $delete->bind_param(
            "siss",
            $operationalDate,
            $id,
            $clientId,
            $name
          );

          if (!$delete->execute()) {
            $delete->close();
            throw new RuntimeException(
              'Fila DaVez indisponível para atualização.'
            );
          }

          $removed = (int) $delete->affected_rows;
          $delete->close();

          $publicIdentityStore->revokeActiveSessions(
            $id,
            'admin',
            $revokedAt
          );
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
          $operationalDate,
          &$insertedId
        ): void {
          $insert = $conn->prepare(
            "INSERT INTO checkins
               (nome, data_hora, operational_date, ordem,
                is_closed, closed_at, obs)
             VALUES (?, NOW(), ?, ?, 0, NULL, ?)"
          );

          if (!$insert) {
            throw new RuntimeException(
              'Fila indisponível para inserção.'
            );
          }

          $insert->bind_param(
            "ssis",
            $nome,
            $operationalDate,
            $order,
            $obs
          );

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
      ['form_action', '_csrf', 'lat', 'lng', 'raio']
    );
    davez_assert_no_untrusted_identity($_POST);
    davez_require_csrf(
      is_string($_POST['_csrf'] ?? null) ? $_POST['_csrf'] : null
    );

    if (($_POST['form_action'] ?? '') !== 'save_settings') {
      throw new InvalidArgumentException('Ação de formulário inválida.');
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

  $stmt = $conn->prepare(
    "UPDATE settings
     SET lat_base=?, lng_base=?, raio=?
     WHERE id=1"
  );
  $stmt->bind_param("ddi", $lat, $lng, $raio);
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
<link rel="stylesheet" href="assets/css/davez-tech-rc2.css?v=1.2.0-rc2">
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
  --code-bg:#101817;
  --code-ink:#83f0c5;
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
    --code-bg:#070c0b;
    --code-ink:#8ef3ca;
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
  grid-template-columns:repeat(7,minmax(0,1fr));
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
.ticket-panel{border-color:color-mix(in srgb,var(--accent) 42%,var(--line))}
.ticket-result{
  margin-top:18px;
  padding:18px;
  border:1px solid #283633;
  border-radius:var(--radius-lg);
  background:var(--code-bg);
  color:var(--code-ink);
}
.ticket-result[hidden]{display:none}
.ticket-result output{
  display:block;
  margin:8px 0 10px;
  font-family:"Cascadia Mono","SFMono-Regular",Consolas,monospace;
  font-size:clamp(1.45rem,5vw,2.3rem);
  font-weight:800;
  letter-spacing:.08em;
  line-height:1.2;
  overflow-wrap:anywhere;
}
.ticket-result p{margin:5px 0;color:#c4d2cd}
.ticket-actions{display:flex;flex-wrap:wrap;gap:9px;margin-top:14px}
.ticket-actions button{flex:1;min-width:160px}
/* Painel do codigo individual, agora inline na aba Chamada. */
#individualTicketCard{
  border-color:color-mix(in srgb,var(--accent) 30%,var(--line));
}
.ticket-result{
  animation:ticketReveal .5s cubic-bezier(.32,.72,0,1) both;
}
@keyframes ticketReveal{
  from{opacity:0;transform:translateY(10px)}
  to{opacity:1;transform:translateY(0)}
}
#issuedAccessCode{
  color:var(--accent);
  padding:10px 14px;
  border-radius:12px;
  background:color-mix(in srgb,var(--accent) 12%,transparent);
  border:1px solid color-mix(in srgb,var(--accent) 26%,transparent);
}
.qr-panel{
  border-color:color-mix(in srgb,var(--accent) 38%,var(--line));
}
.qr-section-heading{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:20px;
  margin-bottom:22px;
}
.qr-section-heading p{max-width:64ch;color:var(--ink-soft)}
.qr-local-badge{
  flex:0 0 auto;
  display:inline-flex;
  align-items:center;
  min-height:32px;
  padding:6px 10px;
  border:1px solid color-mix(in srgb,var(--success) 38%,var(--line));
  border-radius:9px;
  background:var(--success-soft);
  color:var(--success);
  font-size:.72rem;
  font-weight:850;
  letter-spacing:.055em;
  text-transform:uppercase;
}
.qr-layout{
  display:grid;
  grid-template-columns:minmax(240px,.72fr) minmax(320px,1.28fr);
  align-items:center;
  gap:clamp(22px,5vw,64px);
}
.qr-figure{
  width:min(100%,360px);
  margin:0;
}
.qr-frame{
  display:grid;
  place-items:center;
  padding:clamp(14px,3vw,24px);
  border:1px solid var(--line-strong);
  border-radius:var(--radius-lg);
  background:#fff;
  box-shadow:inset 0 0 0 1px rgba(13,27,23,.05);
}
.qr-canvas{
  display:block;
  width:100%;
  height:auto;
  aspect-ratio:1;
  image-rendering:pixelated;
}
.qr-figure figcaption{
  margin-top:9px;
  color:var(--ink-soft);
  font-size:.78rem;
  text-align:center;
}
.qr-details{min-width:0}
.qr-url{
  display:block;
  margin:10px 0 18px;
  padding:14px 15px;
  border:1px solid var(--line);
  border-radius:11px;
  background:var(--canvas-deep);
  color:var(--ink);
  font-family:"Cascadia Mono","SFMono-Regular",Consolas,monospace;
  font-size:.84rem;
  overflow-wrap:anywhere;
}
.qr-facts{
  display:grid;
  gap:9px;
  margin:0 0 20px;
  padding:0;
  list-style:none;
}
.qr-facts li{
  display:grid;
  grid-template-columns:18px minmax(0,1fr);
  gap:9px;
  color:var(--ink-soft);
  font-size:.88rem;
}
.qr-facts li::before{
  content:"";
  width:7px;
  height:7px;
  margin-top:.48em;
  border-radius:2px;
  background:var(--accent);
}
.qr-actions{
  display:flex;
  flex-wrap:wrap;
  gap:9px;
}
.qr-actions button{flex:1;min-width:148px}
.qr-empty-state{
  display:grid;
  place-items:center;
  min-height:180px;
  margin-top:18px;
  padding:28px;
  border:1px dashed var(--line-strong);
  border-radius:var(--radius-lg);
  color:var(--ink-soft);
  text-align:center;
}
.qr-empty-state[hidden]{display:none}
.qr-ticket-result{
  background:var(--surface-raised);
  color:var(--ink);
}
.qr-ticket-result p{color:var(--ink-soft)}
.ticket-result-heading{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:14px;
  margin-bottom:16px;
}
.ticket-result-heading strong{display:block;margin-top:4px}
.ticket-state{
  display:inline-flex;
  align-items:center;
  min-height:30px;
  padding:5px 10px;
  border:1px solid var(--line);
  border-radius:9px;
  font-size:.72rem;
  font-weight:850;
  letter-spacing:.05em;
  text-transform:uppercase;
}
.ticket-state[data-state="active"]{
  border-color:color-mix(in srgb,var(--success) 38%,var(--line));
  background:var(--success-soft);
  color:var(--success);
}
.ticket-state[data-state="consumed"]{
  border-color:color-mix(in srgb,var(--accent) 42%,var(--line));
  background:var(--accent-soft);
  color:var(--accent-strong);
}
.ticket-state[data-state="expired"],
.ticket-state[data-state="revoked"],
.ticket-state[data-state="unknown"]{
  border-color:color-mix(in srgb,var(--danger) 38%,var(--line));
  background:var(--danger-soft);
  color:var(--danger);
}
.individual-qr-layout{
  display:grid;
  grid-template-columns:minmax(280px,1fr) minmax(200px,300px);
  align-items:center;
  gap:clamp(18px,4vw,42px);
}
.individual-qr-copy{min-width:0}
.individual-qr-copy output{
  margin-top:10px;
  color:var(--code-ink);
}
.ticket-validity{
  display:grid;
  grid-template-columns:repeat(2,minmax(0,1fr));
  gap:9px;
  margin:16px 0;
}
.ticket-validity div{
  padding:11px 12px;
  border:1px solid var(--line);
  border-radius:10px;
  background:var(--canvas-deep);
}
.ticket-validity span{
  display:block;
  color:var(--ink-soft);
  font-size:.7rem;
  font-weight:800;
  letter-spacing:.06em;
  text-transform:uppercase;
}
.ticket-validity time,
.ticket-validity strong{
  display:block;
  margin-top:4px;
  color:var(--ink);
  font-size:.86rem;
}
.individual-qr-figure{width:min(100%,300px);justify-self:end}
.qr-print-sheet{display:none}
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
.support-panel{
  border-color:color-mix(in srgb,var(--accent) 34%,var(--line));
}
.support-heading{
  display:grid;
  grid-template-columns:minmax(220px,.75fr) minmax(280px,1.25fr);
  align-items:end;
  gap:clamp(18px,4vw,52px);
  margin-bottom:24px;
}
.support-heading h2{margin-bottom:0}
.support-heading .support-intro{
  max-width:62ch;
  margin:0;
  color:var(--ink-soft);
}
.support-logs-heading{
  display:flex;
  align-items:center;
  gap:14px;
}
.support-logs-icon{
  display:inline-grid;
  place-items:center;
  width:56px;
  height:56px;
  flex:0 0 auto;
  border-radius:16px;
  color:var(--accent);
  background:color-mix(in srgb,var(--accent) 14%,transparent);
  border:1px solid color-mix(in srgb,var(--accent) 34%,var(--line));
}
.log-line{
  margin-top:6px;
  font-size:13px;
  line-height:1.55;
  color:var(--ink);
}
.log-tag{
  display:inline-block;
  font-size:11px;
  font-weight:700;
  letter-spacing:.02em;
  text-transform:uppercase;
  padding:2px 8px;
  border-radius:999px;
  margin-right:6px;
}
.log-tag-motivo{
  color:var(--danger);
  background:color-mix(in srgb,var(--danger) 16%,transparent);
}
.log-tag-solucao{
  color:var(--success);
  background:color-mix(in srgb,var(--success) 18%,transparent);
}
.btn-locate{
  display:inline-flex;
  align-items:center;
  gap:8px;
  min-height:44px;
  padding:10px 16px;
  border-radius:12px;
  border:1px solid color-mix(in srgb,var(--accent) 30%,var(--line));
  background:color-mix(in srgb,var(--accent) 10%,transparent);
  color:var(--accent);
  font-weight:600;
  font-size:14px;
  cursor:pointer;
  transition:background 160ms ease,border-color 160ms ease,transform 120ms ease;
}
.btn-locate:hover{
  background:color-mix(in srgb,var(--accent) 16%,transparent);
  border-color:color-mix(in srgb,var(--accent) 44%,var(--line));
}
.btn-locate:active{transform:scale(.98);}
.btn-locate:focus-visible{
  outline:3px solid color-mix(in srgb,var(--accent) 45%,transparent);
  outline-offset:2px;
}
.btn-locate[aria-busy="true"]{opacity:.7;cursor:progress;}
.btn-locate svg{flex:0 0 auto;}
.report-rank-title{margin:22px 0 4px;font-size:16px;font-weight:600;}
.report-rank-help{margin:0 0 10px;}
#supportLogBox{
  max-height:min(52vh,420px);
  overflow-y:auto;
}
.support-grid{
  display:grid;
  grid-template-columns:repeat(2,minmax(0,1fr));
  gap:12px;
  margin:0;
  font-style:normal;
}
.support-card{
  display:flex;
  min-width:0;
  min-height:240px;
  flex-direction:column;
  padding:20px;
  border:1px solid var(--line);
  border-top:3px solid var(--accent);
  border-radius:var(--radius-lg);
  background:var(--surface-raised);
}
.support-label{
  color:var(--ink-soft);
  font-size:.7rem;
  font-weight:850;
  letter-spacing:.1em;
  text-transform:uppercase;
}
.support-value{
  display:block;
  margin-top:8px;
  color:var(--ink);
  font-size:clamp(1rem,2vw,1.22rem);
  letter-spacing:-.015em;
  overflow-wrap:anywhere;
}
.support-card p{
  color:var(--ink-soft);
  font-size:.88rem;
}
.support-action{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  min-height:44px;
  margin-top:auto;
  padding:10px 14px;
  border:1px solid color-mix(in srgb,var(--accent) 45%,var(--line));
  border-radius:11px;
  background:color-mix(in srgb,var(--accent-soft) 78%,var(--surface-raised));
  color:var(--accent-strong);
  font-weight:800;
  text-decoration:none;
  transition:transform .2s var(--ease),background-color .2s var(--ease),border-color .2s var(--ease);
}
.support-action:hover{
  border-color:var(--accent);
  background:var(--accent-soft);
  transform:translateY(-1px);
}
.support-action:active{transform:translateY(1px)}
.support-note{
  margin:18px 0 0 !important;
  padding-top:16px;
  border-top:1px solid var(--line);
  color:var(--ink-soft);
  font-size:.82rem;
}
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
/* Coesao visual: numeros estaveis, tier de metricas com acento e entrada por secao. */
.dash .v,
#issuedTicketCountdown,
.ticket-validity time,
.ticket-validity strong{
  font-variant-numeric:tabular-nums;
}
.mcard{position:relative;overflow:hidden}
.mcard::before{
  content:"";
  position:absolute;
  left:0;
  right:0;
  top:0;
  height:3px;
  background:linear-gradient(90deg,var(--accent),color-mix(in srgb,var(--accent) 12%,transparent));
}
.section{animation:sectionReveal .45s var(--ease) both}
@keyframes sectionReveal{
  from{opacity:0;transform:translateY(8px)}
  to{opacity:1;transform:translateY(0)}
}
/* ===== Ranking de motoboys ===== */
.ranking-heading{
  display:flex;
  flex-wrap:wrap;
  align-items:flex-end;
  justify-content:space-between;
  gap:16px;
  margin-bottom:8px;
}
.ranking-intro{max-width:60ch;color:var(--ink-soft)}
.ranking-filter{
  display:inline-flex;
  gap:4px;
  padding:4px;
  border:1px solid var(--line);
  border-radius:12px;
  background:var(--surface-muted);
}
.ranking-period{
  width:auto;
  min-height:40px;
  padding:6px 16px;
  border:1px solid transparent;
  border-radius:9px;
  background:transparent;
  color:var(--ink-soft);
  font-weight:750;
  transition:background-color .2s var(--ease),color .2s var(--ease),border-color .2s var(--ease);
}
.ranking-period.active{
  background:var(--surface-raised);
  color:var(--accent-strong);
  border-color:var(--line);
  box-shadow:0 2px 8px rgba(30,50,45,.08);
}
.ranking-list{display:flex;flex-direction:column;gap:10px;margin-top:12px}
.ranking-row{
  display:grid;
  grid-template-columns:auto 1fr auto;
  align-items:center;
  gap:16px;
  padding:14px 16px;
  border:1px solid var(--line);
  border-radius:var(--radius-lg);
  background:var(--surface-raised);
}
.ranking-pos{
  display:inline-grid;
  place-items:center;
  width:40px;
  height:40px;
  border-radius:12px;
  background:var(--surface-muted);
  color:var(--ink-soft);
  font-weight:850;
  font-variant-numeric:tabular-nums;
}
.ranking-row[data-top="1"] .ranking-pos{background:#f6c945;color:#3a2c00}
.ranking-row[data-top="2"] .ranking-pos{background:#cdd4dc;color:#26303a}
.ranking-row[data-top="3"] .ranking-pos{background:#e2a06a;color:#3a2200}
.ranking-main{min-width:0}
.ranking-name{font-weight:800;overflow-wrap:anywhere}
.ranking-metrics{
  display:flex;
  flex-wrap:wrap;
  gap:4px 14px;
  margin-top:4px;
  color:var(--ink-soft);
  font-size:.82rem;
  font-variant-numeric:tabular-nums;
}
.ranking-metrics b{color:var(--ink);font-weight:800}
.ranking-side{
  display:flex;
  flex-direction:column;
  align-items:flex-end;
  gap:6px;
}
.ranking-score{
  font-size:1.15rem;
  font-weight:850;
  color:var(--accent-strong);
  font-variant-numeric:tabular-nums;
}
.ranking-score span{font-size:.7rem;font-weight:750;color:var(--ink-soft)}
.ranking-evo{font-size:.78rem;font-weight:800}
.ranking-evo[data-dir="up"]{color:var(--success)}
.ranking-evo[data-dir="down"]{color:var(--danger)}
.ranking-evo[data-dir="flat"]{color:var(--ink-soft)}
.ranking-spark{display:block;width:96px;height:28px;color:var(--accent)}
@media (max-width:640px){
  .ranking-row{grid-template-columns:auto 1fr}
  .ranking-side{
    grid-column:1 / -1;
    flex-direction:row;
    justify-content:space-between;
    align-items:center;
  }
}
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
  .tabs{grid-template-columns:repeat(3,minmax(0,1fr))}
  .dash{grid-template-columns:repeat(2,minmax(0,1fr))}
  .dash .mcard:first-child{grid-column:span 2}
  .row{grid-template-columns:1fr 1fr}
  .row-action{grid-column:1/-1}
  .row-action button{width:100%}
  .queue-item,.dv-item,.report-item{align-items:flex-start;flex-direction:column}
  .item-actions{width:100%;justify-content:flex-end}
  .support-heading{grid-template-columns:1fr;align-items:start}
  .qr-layout,.individual-qr-layout{grid-template-columns:1fr}
  .qr-figure,.individual-qr-figure{justify-self:center}
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
  .support-grid{grid-template-columns:1fr}
  .support-card{min-height:0}
  .qr-section-heading,.ticket-result-heading{flex-direction:column}
  .qr-actions button{min-width:100%}
  .ticket-validity{grid-template-columns:1fr}
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
@media print{
  @page{margin:14mm}
  body.qr-printing{background:#fff;color:#111}
  body.qr-printing > :not(.qr-print-sheet){display:none !important}
  body.qr-printing .qr-print-sheet{
    display:grid !important;
    min-height:250mm;
    place-items:center;
    align-content:center;
    gap:8mm;
    color:#111;
    text-align:center;
  }
  .qr-print-sheet img{
    width:92mm;
    height:92mm;
    image-rendering:pixelated;
  }
  .qr-print-sheet h1{margin:0;font-size:22pt}
  .qr-print-sheet p{
    max-width:150mm;
    margin:0;
    font-family:"Cascadia Mono","SFMono-Regular",Consolas,monospace;
    font-size:10pt;
    overflow-wrap:anywhere;
  }
}

/* ===== DaVez Tech Admin RC1 ===== */
:root{
  --canvas:#edf3ff;
  --canvas-deep:#dde8fb;
  --surface:#f6f9ff;
  --surface-raised:#ffffff;
  --surface-muted:#eaf1ff;
  --ink:#07182f;
  --ink-soft:#5a6b87;
  --line:#c8d6ef;
  --line-strong:#9eb4d7;
  --accent:#2d68ed;
  --accent-strong:#1849b8;
  --accent-soft:#dfebff;
  --warning:#bc5b0c;
  --warning-soft:#fff0df;
  --danger:#b82c43;
  --danger-strong:#8f1d31;
  --danger-soft:#ffe5ea;
  --success:#137653;
  --success-soft:#ddf6ec;
  --code-bg:#071326;
  --code-ink:#70e7ff;
  --shadow:0 24px 65px rgba(30,70,145,.13);
  --shadow-small:0 10px 28px rgba(30,70,145,.10);
  --focus:#2f72ff;
  --tech-orange:#ff8b2b;
  --tech-cyan:#43d9ff;
  --tech-mono:"Cascadia Code","SFMono-Regular",Consolas,monospace;
}
body{
  background:
    radial-gradient(circle at 8% 4%,rgba(47,114,255,.16),transparent 34rem),
    radial-gradient(circle at 93% 90%,rgba(255,139,43,.09),transparent 28rem),
    linear-gradient(145deg,var(--canvas),var(--canvas-deep));
  background-attachment:fixed;
}
body::before{
  position:fixed;inset:0;z-index:-1;pointer-events:none;content:"";
  opacity:.42;
  background-image:linear-gradient(rgba(40,91,188,.045) 1px,transparent 1px),linear-gradient(90deg,rgba(40,91,188,.045) 1px,transparent 1px);
  background-size:32px 32px;
  mask-image:radial-gradient(circle at 50% 20%,#000,transparent 78%);
}
.admin-system-rail{
  display:flex;align-items:center;gap:8px;margin:0 0 16px;overflow-x:auto;scrollbar-width:none;
}
.admin-system-rail::-webkit-scrollbar{display:none}
.admin-system-chip{
  display:inline-flex;align-items:center;gap:7px;min-height:30px;padding:5px 10px;flex:0 0 auto;
  border:1px solid color-mix(in srgb,var(--accent) 22%,var(--line));border-radius:999px;
  color:var(--ink-soft);background:color-mix(in srgb,var(--surface-raised) 72%,transparent);
  font:750 10px/1 var(--tech-mono);letter-spacing:.07em;text-transform:uppercase;
  backdrop-filter:blur(14px);
}
.admin-system-chip[data-online]::before{
  width:7px;height:7px;border-radius:50%;background:#20cf86;box-shadow:0 0 0 3px rgba(32,207,134,.13),0 0 12px rgba(32,207,134,.48);content:"";
}
.admin-header{
  padding:18px 20px;border:1px solid color-mix(in srgb,var(--accent) 18%,var(--line));border-radius:var(--radius-xl);
  background:linear-gradient(135deg,color-mix(in srgb,var(--surface-raised) 92%,transparent),color-mix(in srgb,var(--accent-soft) 28%,var(--surface-raised)));
  box-shadow:var(--shadow-small);
}
.titulo-admin img{padding:8px;border-radius:13px;background:#fff;box-shadow:0 8px 22px rgba(20,55,120,.12)}
.titulo-admin span{background:linear-gradient(105deg,var(--ink) 0 68%,var(--accent) 100%);background-clip:text;-webkit-background-clip:text;color:transparent}
.tabs-wrap{top:8px;border-color:color-mix(in srgb,var(--accent) 18%,var(--line));background:color-mix(in srgb,var(--surface) 84%,transparent);box-shadow:0 12px 36px rgba(25,62,132,.11)}
.tab[aria-selected="true"]{border-color:color-mix(in srgb,var(--accent) 28%,var(--line));color:var(--accent-strong);background:linear-gradient(180deg,var(--surface-raised),var(--accent-soft));box-shadow:0 8px 18px rgba(45,104,237,.12)}
.card,.mcard{
  position:relative;overflow:hidden;border-color:color-mix(in srgb,var(--accent) 14%,var(--line));
  background:linear-gradient(180deg,var(--surface-raised),color-mix(in srgb,var(--surface) 95%,var(--accent-soft)));
  box-shadow:var(--shadow-small);
}
.card::before,.mcard::before{
  position:absolute;top:0;right:18px;width:68px;height:3px;border-radius:0 0 99px 99px;
  background:linear-gradient(90deg,var(--tech-cyan),var(--accent),var(--tech-orange));content:"";
}
button.btn-primary,.btn-primary{background:linear-gradient(100deg,#245be2,#3c75ff);box-shadow:0 10px 24px rgba(45,104,237,.23)}
.btn-toggle{box-shadow:0 10px 26px rgba(45,104,237,.17)}
input,select,textarea{border-color:color-mix(in srgb,var(--accent) 20%,var(--line));background:color-mix(in srgb,var(--surface-raised) 92%,var(--accent-soft))}
input:focus,select:focus,textarea:focus{border-color:var(--accent);box-shadow:0 0 0 4px rgba(45,104,237,.12)}
.ranking-custom-filter,.report-filter-bar{
  display:grid;grid-template-columns:repeat(2,minmax(150px,1fr)) auto auto;align-items:end;gap:10px;margin:16px 0;
  padding:13px;border:1px solid var(--line);border-radius:14px;background:var(--surface-muted);
}
.compact-field{display:grid;gap:5px}.compact-field label{font-size:.72rem;font-weight:800;color:var(--ink-soft)}
.pagination-bar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:14px;padding-top:12px;border-top:1px solid var(--line)}
.pagination-actions{display:flex;gap:7px}.pagination-actions button{min-height:38px;padding:7px 12px}
.report-summary{font:700 .76rem/1.4 var(--tech-mono);color:var(--ink-soft)}
.pdf-action{display:inline-flex;align-items:center;justify-content:center;text-decoration:none}
@media (prefers-color-scheme:dark){
  :root{--canvas:#030916;--canvas-deep:#071326;--surface:#09162b;--surface-raised:#0d1c34;--surface-muted:#10213c;--ink:#eff5ff;--ink-soft:#9aacc8;--line:#263c60;--line-strong:#48638e;--accent:#6d94ff;--accent-strong:#c4d4ff;--accent-soft:#172d55;--warning:#ffc079;--warning-soft:#4c2e13;--danger:#ff95a5;--danger-strong:#ffc2cb;--danger-soft:#4d1f2b;--success:#75dfb0;--success-soft:#153f31;--code-bg:#020711;--code-ink:#72e8ff;--shadow:0 26px 70px rgba(0,0,0,.42);--shadow-small:0 12px 32px rgba(0,0,0,.3);--focus:#82a9ff}
  body{background:radial-gradient(circle at 8% 4%,rgba(49,97,214,.25),transparent 34rem),radial-gradient(circle at 93% 90%,rgba(255,123,35,.08),transparent 28rem),linear-gradient(145deg,#020711,#071326)}
  .admin-header,.card,.mcard{background:linear-gradient(180deg,rgba(13,28,52,.97),rgba(8,20,39,.97))}
  .admin-system-chip{background:rgba(14,30,55,.74)}
  .titulo-admin span{background-image:linear-gradient(105deg,#f4f7ff 0 68%,#83a5ff 100%)}
  input,select,textarea{background:#0a1a32}
}
@media(max-width:760px){.ranking-custom-filter,.report-filter-bar{grid-template-columns:1fr 1fr}.ranking-custom-filter button,.report-filter-bar button{width:100%}}
@media(max-width:470px){.ranking-custom-filter,.report-filter-bar{grid-template-columns:1fr}.admin-header{padding:15px}.admin-system-chip:nth-child(3){display:none}}

</style>
</head>
<body class="admin-app">
<a class="skip-link" href="#admin-content">Ir para o conteúdo</a>
<main class="container" id="admin-content">
  <div class="admin-system-rail" aria-label="Estado do painel">
    <span class="admin-system-chip" data-online>Operação conectada</span>
    <span class="admin-system-chip">Ciclo <strong><?= htmlspecialchars($operationalCycleLabel, ENT_QUOTES, 'UTF-8') ?></strong></span>
    <span class="admin-system-chip">Atualização <strong>12s</strong></span>
    <span class="admin-system-chip">Data operacional <strong><?= htmlspecialchars($operationalDate, ENT_QUOTES, 'UTF-8') ?></strong></span>
    <span class="admin-system-chip" id="serverClockChip" data-state="ok">Relógio <strong>sincronizando…</strong></span>
  </div>
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
      <button type="button" class="tab" id="tab-ranking" role="tab"
        aria-controls="ranking" aria-selected="false" tabindex="-1" data-tab="ranking">
        <span aria-hidden="true">🏆</span> Ranking
      </button>
      <button type="button" class="tab" id="tab-relatorio" role="tab"
        aria-controls="relatorio" aria-selected="false" tabindex="-1" data-tab="relatorio">
        <span aria-hidden="true">📄</span> Relatórios
      </button>
      <button type="button" class="tab" id="tab-config" role="tab"
        aria-controls="config" aria-selected="false" tabindex="-1" data-tab="config">
        <span aria-hidden="true">⚙️</span> Configurações
      </button>
      <button type="button" class="tab" id="tab-qrcode" role="tab"
        aria-controls="qrcode" aria-selected="false" tabindex="-1" data-tab="qrcode">
        <span aria-hidden="true">▦</span> QR Code
      </button>
      <button type="button" class="tab" id="tab-suporte" role="tab"
        aria-controls="suporte" aria-selected="false" tabindex="-1" data-tab="suporte">
        <span aria-hidden="true">✉️</span> Suporte
      </button>
    </div>
  </nav>

  <section id="chamada" class="section active" role="tabpanel"
    aria-labelledby="tab-chamada" tabindex="0">
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
        <button type="button" class="btn-primary" id="btnIssueCheckinTicket">Gerar código/QR individual</button>
      </div>
      <p><small class="mini">Atualização automática a cada 12 segundos. O ciclo operacional vira às <?= htmlspecialchars($operationalCycleLabel, ENT_QUOTES, 'UTF-8') ?>.</small></p>
    </section>

    <section class="card ticket-panel" id="individualTicketCard"
      aria-labelledby="individual-codes-title">
      <div class="qr-section-heading">
        <div>
          <p class="eyebrow">Acesso público v2</p>
          <h2 id="individual-codes-title">Código e QR individual</h2>
          <p>
            Cada clique em <strong>Gerar código/QR individual</strong> cria um
            código diário reutilizável para o motoboy. Ele vale até a virada
            operacional e pode ser usado para check-in, reentrada e recuperação.
          </p>
        </div>
      </div>

      <div class="qr-empty-state" id="individualQrEmpty">
        Nenhum código emitido ainda. Use <strong>Gerar código/QR individual</strong> na barra acima.
      </div>

      <div class="ticket-result qr-ticket-result" id="issuedTicketResult"
        role="status" aria-live="polite" aria-atomic="true" tabindex="-1" hidden>
        <div class="ticket-result-heading">
          <div>
            <span class="eyebrow">Código individual</span>
            <strong id="issuedTicketPurpose">Código emitido</strong>
          </div>
          <span class="ticket-state" id="issuedTicketState" data-state="active">Ativo</span>
        </div>

        <div class="individual-qr-layout">
          <div class="individual-qr-copy">
            <output id="issuedAccessCode"></output>
            <div class="ticket-validity">
              <div>
                <span>Válido até</span>
                <time id="issuedTicketExpiry"></time>
              </div>
              <div>
                <span>Tempo restante</span>
                <strong id="issuedTicketCountdown">--:--</strong>
              </div>
            </div>
            <p id="issuedTicketWarning"></p>
          </div>

          <figure class="qr-figure individual-qr-figure">
            <div class="qr-frame">
              <canvas class="qr-canvas" id="individualQrCanvas" width="640" height="640"
                role="img" aria-label="QR Code individual ainda não emitido">
                Seu navegador não conseguiu exibir o QR Code individual.
              </canvas>
            </div>
            <figcaption>Peça ao motoboy para ler este QR ou digitar o código.</figcaption>
          </figure>
        </div>

        <div class="ticket-actions">
          <button type="button" class="btn-primary" id="btnCopyTicket">Copiar código</button>
          <button type="button" class="btn-secondary" id="btnRefreshTicket">Atualizar (novo código)</button>
          <button type="button" class="btn-secondary" id="btnHideTicket">Ocultar código</button>
        </div>
      </div>
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

  <section id="ranking" class="section" role="tabpanel"
    aria-labelledby="tab-ranking" tabindex="0" hidden>
    <section class="card" aria-labelledby="ranking-title">
      <div class="ranking-heading section-heading-pro">
        <div>
          <p class="eyebrow">Desempenho</p>
          <h2 id="ranking-title">Ranking de Motoboys</h2>
          <p class="ranking-intro">
            Classificação por entregas despachadas, com evolução por período.
            Os dados começam a contar a partir de agora, a cada "Saiu para entrega".
          </p>
        </div>
        <div class="section-actions">
          <div class="ranking-filter" role="group" aria-label="Período do ranking">
            <button type="button" class="ranking-period active" data-periodo="dia"
              aria-pressed="true">Dia</button>
            <button type="button" class="ranking-period" data-periodo="semana"
              aria-pressed="false">Semana</button>
            <button type="button" class="ranking-period" data-periodo="mes"
              aria-pressed="false">Mês</button>
          </div>
          <button type="button" class="pdf-action" id="btnRankingPdf">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"
              stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M7 3h7l4 4v14H7z"></path><path d="M14 3v5h5"></path>
              <path d="M9 15h6M9 18h4"></path>
            </svg>
            Gerar PDF do ranking
          </button>
        </div>
      </div>

      <div class="ranking-custom-filter" aria-label="Intervalo personalizado do ranking">
        <div class="compact-field">
          <label for="rankingDateFromText">Data inicial</label>
          <div class="date-combo">
            <input id="rankingDateFromText" type="text" inputmode="numeric" autocomplete="off"
              placeholder="MM/DD/YYYY" aria-describedby="ranking-date-hint" data-date-text="rankingDateFrom">
            <button type="button" class="date-picker-button" data-date-picker="rankingDateFrom"
              aria-label="Abrir calendário para esta data">
              <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"
                stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <rect x="3" y="5" width="18" height="16" rx="2"></rect>
                <path d="M16 3v4M8 3v4M3 10h18"></path>
              </svg>
            </button>
            <input id="rankingDateFrom" class="native-date-proxy" type="date" tabindex="-1" aria-hidden="true">
          </div>
        </div>
        <div class="compact-field">
          <label for="rankingDateToText">Data final</label>
          <div class="date-combo">
            <input id="rankingDateToText" type="text" inputmode="numeric" autocomplete="off"
              placeholder="MM/DD/YYYY" aria-describedby="ranking-date-hint" data-date-text="rankingDateTo">
            <button type="button" class="date-picker-button" data-date-picker="rankingDateTo"
              aria-label="Abrir calendário para esta data">
              <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"
                stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <rect x="3" y="5" width="18" height="16" rx="2"></rect>
                <path d="M16 3v4M8 3v4M3 10h18"></path>
              </svg>
            </button>
            <input id="rankingDateTo" class="native-date-proxy" type="date" tabindex="-1" aria-hidden="true">
          </div>
        </div>
        <button type="button" class="btn-primary" id="btnApplyRankingRange">Aplicar intervalo</button>
        <button type="button" class="btn-secondary" id="btnClearRankingRange">Limpar</button>
      </div>
      <small class="date-format-hint" id="ranking-date-hint">Use o calendário ou digite no formato MM/DD/YYYY. O servidor valida e normaliza a data.</small>

      <div class="analytics-strip" aria-label="Resumo do ranking">
        <div class="analytics-card"><span>Classificados</span><strong id="rankingTotal">0</strong></div>
        <div class="analytics-card"><span>Página</span><strong id="rankingPageSummary">1 / 1</strong></div>
        <div class="analytics-card"><span>Atualização</span><strong id="rankingUpdatedAt">--:--</strong></div>
      </div>
      <p class="mini" id="rankingRange" role="status" aria-live="polite"></p>

      <div id="rankingBox" class="ranking-list" role="region"
        aria-label="Classificação dos motoboys" aria-live="polite"
        aria-busy="true" tabindex="0">
        <div class="state-row" data-state="loading">Carregando ranking…</div>
      </div>
      <div id="rankingPagination" class="pagination-bar" aria-live="polite"></div>
    </section>
  </section>

  <section id="relatorio" class="section" role="tabpanel"
    aria-labelledby="tab-relatorio" tabindex="0" hidden>
    <section class="card" aria-labelledby="report-title">
      <div class="section-heading-pro">
        <div>
          <p class="eyebrow">Histórico operacional</p>
          <h2 id="report-title">Relatórios salvos</h2>
          <p><small class="mini">Consulte, filtre, pagine e gere arquivos PDF sem sair do painel.</small></p>
        </div>
        <div class="section-actions">
          <button type="button" class="pdf-action" id="btnReportsPdf">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"
              stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M7 3h7l4 4v14H7z"></path><path d="M14 3v5h5"></path>
              <path d="M9 15h6M9 18h4"></path>
            </svg>
            Gerar lista em PDF
          </button>
        </div>
      </div>
      <div class="report-filter-bar" aria-label="Filtros dos relatórios">
        <div class="compact-field">
          <label for="reportDateFromText">Data inicial</label>
          <div class="date-combo">
            <input id="reportDateFromText" type="text" inputmode="numeric" autocomplete="off"
              placeholder="MM/DD/YYYY" aria-describedby="report-date-hint" data-date-text="reportDateFrom">
            <button type="button" class="date-picker-button" data-date-picker="reportDateFrom"
              aria-label="Abrir calendário para esta data">
              <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"
                stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <rect x="3" y="5" width="18" height="16" rx="2"></rect>
                <path d="M16 3v4M8 3v4M3 10h18"></path>
              </svg>
            </button>
            <input id="reportDateFrom" class="native-date-proxy" type="date" tabindex="-1" aria-hidden="true">
          </div>
        </div>
        <div class="compact-field">
          <label for="reportDateToText">Data final</label>
          <div class="date-combo">
            <input id="reportDateToText" type="text" inputmode="numeric" autocomplete="off"
              placeholder="MM/DD/YYYY" aria-describedby="report-date-hint" data-date-text="reportDateTo">
            <button type="button" class="date-picker-button" data-date-picker="reportDateTo"
              aria-label="Abrir calendário para esta data">
              <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"
                stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <rect x="3" y="5" width="18" height="16" rx="2"></rect>
                <path d="M16 3v4M8 3v4M3 10h18"></path>
              </svg>
            </button>
            <input id="reportDateTo" class="native-date-proxy" type="date" tabindex="-1" aria-hidden="true">
          </div>
        </div>
        <button type="button" class="btn-primary" id="btnApplyReportFilters">Pesquisar</button>
        <button type="button" class="btn-secondary" id="btnClearReportFilters">Limpar</button>
      </div>
      <small class="date-format-hint" id="report-date-hint">A lista mostra 15 relatórios por página. Use o calendário ou digite MM/DD/YYYY.</small>
      <div class="analytics-strip" aria-label="Resumo dos relatórios">
        <div class="analytics-card"><span>Encontrados</span><strong id="reportsTotal">0</strong></div>
        <div class="analytics-card"><span>Página</span><strong id="reportsPageSummary">1 / 1</strong></div>
        <div class="analytics-card"><span>Filtro</span><strong id="reportsFilterSummary">Todos</strong></div>
      </div>
      <div id="lastReportBox" aria-live="polite" aria-busy="true">
        <div class="state-row" data-state="loading">Carregando relatórios...</div>
      </div>
      <div id="reportPagination" class="pagination-bar" aria-live="polite"></div>
    </section>
  </section>

  <section id="config" class="section" role="tabpanel"
    aria-labelledby="tab-config" tabindex="0" hidden>
    <section class="card" aria-labelledby="config-title">
      <h2 id="config-title">Configurações da lista</h2>
      <form id="configForm">
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
          <button type="button" class="btn-locate" id="btnLocateMe" aria-describedby="locateStatus">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"
              stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"
              aria-hidden="true" focusable="false">
              <path d="M12 21s-6-5.3-6-10a6 6 0 0 1 12 0c0 4.7-6 10-6 10z"></path>
              <circle cx="12" cy="11" r="2.4"></circle>
            </svg>
            <span>Me localizar</span>
          </button>
          <small class="field-help" id="locateStatus" role="status" aria-live="polite">Usa o GPS para preencher a latitude e a longitude com a sua posição atual. Funciona no PC e no celular.</small>
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

  <section id="qrcode" class="section" role="tabpanel"
    aria-labelledby="tab-qrcode" tabindex="0" hidden>
    <section class="card qr-panel" aria-labelledby="permanent-qr-title">
      <div class="qr-section-heading">
        <div>
          <p class="eyebrow">Acesso fixo da loja</p>
          <h2 id="permanent-qr-title">QR permanente de acesso</h2>
          <p>
            Este QR contém somente o endereço público. Ele pode ser impresso e
            colocado na loja; cada motoboy ainda informa seu código individual.
          </p>
        </div>
        <span class="qr-local-badge">Geração local</span>
      </div>

      <div class="qr-layout">
        <figure class="qr-figure">
          <div class="qr-frame">
            <canvas class="qr-canvas" id="permanentQrCanvas" width="640" height="640"
              role="img" aria-label="QR Code permanente para abrir a tela pública DaVez">
              Seu navegador não conseguiu exibir o QR Code.
            </canvas>
          </div>
          <figcaption>Visualizar QR permanente</figcaption>
        </figure>

        <div class="qr-details">
          <p class="eyebrow">Endereço público</p>
          <output class="qr-url" id="publicQrUrl" aria-live="polite"></output>
          <ul class="qr-facts">
            <li>Não contém senha, sessão, identificação do motoboy ou código individual.</li>
            <li>Permanece válido enquanto o endereço público do sistema não mudar.</li>
            <li>A leitura abre diretamente a tela de cadastro e check-in.</li>
          </ul>
          <div class="qr-actions">
            <button type="button" class="btn-primary" id="btnDownloadPermanentQr">Baixar PNG</button>
            <button type="button" class="btn-secondary" id="btnPrintPermanentQr">Imprimir</button>
            <button type="button" class="btn-secondary" id="btnCopyPublicUrl">Copiar endereço</button>
          </div>
          <p class="mini" id="permanentQrState" role="status" aria-live="polite">
            Preparando QR permanente…
          </p>
        </div>
      </div>
    </section>
  </section>

  <section id="suporte" class="section" role="tabpanel"
    aria-labelledby="tab-suporte" tabindex="0" hidden>
    <section class="card support-panel" aria-labelledby="support-title">
      <div class="support-heading">
        <div>
          <p class="eyebrow">Contato direto</p>
          <h2 id="support-title">Suporte DaVez</h2>
        </div>
        <p class="support-intro">
          Precisa de ajuda com acesso, configuração ou operação? Fale diretamente com Fernando.
        </p>
      </div>

      <address class="support-grid">
        <article class="support-card">
          <span class="support-label">E-mail</span>
          <strong class="support-value">fernando.augusto.peralta@gmail.com</strong>
          <p>Para dúvidas detalhadas, registros de erro e solicitações que podem ser respondidas por escrito.</p>
          <a class="support-action" href="mailto:fernando.augusto.peralta@gmail.com"
            aria-label="Enviar e-mail para o suporte DaVez">
            <span>Enviar e-mail</span>
            <span aria-hidden="true">↗</span>
          </a>
        </article>

        <article class="support-card">
          <span class="support-label">WhatsApp</span>
          <strong class="support-value">+55 (48) 9 9216-3264</strong>
          <p>Para contato rápido durante a operação e orientação sobre o uso do painel.</p>
          <a class="support-action"
            href="https://wa.me/5548992163264?text=Ol%C3%A1%2C%20Fernando.%20Preciso%20de%20suporte%20no%20DaVez."
            target="_blank" rel="noopener noreferrer"
            aria-label="Abrir conversa com o suporte DaVez no WhatsApp em uma nova aba">
            <span>Abrir WhatsApp</span>
            <span aria-hidden="true">↗</span>
          </a>
        </article>
      </address>

      <p class="support-note">
        Para sua segurança, não envie senhas, códigos individuais, tokens ou dados pessoais desnecessários.
      </p>
    </section>

    <?php if ($adminCanViewLogs): ?>
    <section class="card support-panel" aria-labelledby="support-logs-title">
      <div class="support-heading">
        <div class="support-logs-heading">
          <span class="support-logs-icon" aria-hidden="true">
            <svg width="40" height="40" viewBox="0 0 24 24" fill="none"
              stroke="currentColor" stroke-width="1.6" stroke-linecap="round"
              stroke-linejoin="round" role="img"
              aria-label="Ícone de registro de erros">
              <path d="M9 9a3 3 0 0 1 6 0v1"></path>
              <path d="M7.5 11.5h9v3a4.5 4.5 0 0 1-9 0v-3z"></path>
              <path d="M12 11.5v8"></path>
              <path d="M7.5 13.5h-3"></path>
              <path d="M16.5 13.5h3"></path>
              <path d="M8 17.5l-2.5 1.5"></path>
              <path d="M16 17.5l2.5 1.5"></path>
              <path d="M8 8L6 6.5"></path>
              <path d="M16 8l2-1.5"></path>
              <path d="M4 20L20 4"></path>
            </svg>
          </span>
          <div>
            <p class="eyebrow">Diagnóstico</p>
            <h2 id="support-logs-title">Logs de erros e bugs</h2>
          </div>
        </div>
        <button type="button" class="support-action" id="btnRefreshLogs">
          <span>Atualizar</span>
          <span aria-hidden="true">↻</span>
        </button>
      </div>

      <p class="support-intro">
        Últimos eventos registrados pelo sistema, do mais recente ao mais antigo. Sem dados pessoais: apenas rótulo do evento, horário e métricas operacionais.
      </p>

      <div id="supportLogBox" class="report-list" role="region"
        aria-label="Registros de erro e diagnóstico do sistema"
        aria-live="polite" aria-busy="false" tabindex="0">
        <div class="state-row" data-state="loading">Carregando registros…</div>
      </div>
    </section>
    <?php endif; ?>
  </section>
</main>

<section class="qr-print-sheet" id="qrPrintSheet" aria-hidden="true">
  <img id="qrPrintImage" alt="QR Code DaVez para impressão">
  <h1 id="qrPrintTitle">DaVez</h1>
  <p id="qrPrintValue"></p>
  <p id="qrPrintInstruction"></p>
</section>

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

<script src="js/sortable-1.15.0.min.js"></script>
<script src="js/qrcode-generator-1.4.4.min.js"></script>
<script>
let carregando = false;
let pausado = false;
const CSRF_TOKEN = <?= json_encode(davez_csrf_token(), JSON_UNESCAPED_SLASHES) ?>;
const DV_LIST_URL = "DaVez/listar_admin.php?v=1";
const DV_SAIR_URL = "DaVez/sair.php?v=1";
const DV_REORDER_URL = "DaVez/reordenar.php?v=1";

let dvCarregando = false;
let dvLast = 0;
let dvSortable = null;
let dvPausado = false;

let sortable = null;
let toastTimer = null;
let dialogResolver = null;
let dialogPreviousFocus = null;
let sessionRedirecting = false;
let permanentQrReady = false;
let currentIssuedTicket = null;
let ticketStatusChecking = false;

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
  if (id === 'ranking') carregarRanking(rankingPeriodoAtual);
  if (id === 'config') carregarConfig();
  if (id === 'qrcode') initializePermanentQr();
  if (id === 'suporte') carregarLogs();
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

async function carregarConfig(){
  try {
    const d = await fetchJsonAdmin("admin.php?action=dados");
    document.getElementById('lat').value = d.lat_base ?? '';
    document.getElementById('lng').value = d.lng_base ?? '';
    document.getElementById('raio').value = d.raio ?? '';
  } catch (error) {
    if (!(error instanceof AdminAuthenticationRequiredError)) {
      showToast(error.message || 'Não foi possível carregar as configurações.', false);
    }
  }
}

async function salvar(){
  const button = document.getElementById('btnSaveSettings');
  let f = new FormData();
  f.append("form_action", "save_settings");
  f.append("_csrf", CSRF_TOKEN);
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
    await carregarConfig();
  } catch (error) {
    if (!(error instanceof AdminAuthenticationRequiredError)) {
      showToast(error.message || "Falha ao salvar configurações.", false);
    }
  } finally {
    setButtonBusy(button, false);
  }
}

function getPublicAppUrl(){
  const publicUrl = new URL('./', window.location.href);
  publicUrl.search = '';
  publicUrl.hash = '';
  return publicUrl.href;
}

function getIndividualAccessUrl(accessCode){
  const publicUrl = new URL(getPublicAppUrl());
  publicUrl.hash = new URLSearchParams({
    access_code:accessCode
  }).toString();
  return publicUrl.href;
}

function renderQrToCanvas(canvas, value, accessibleLabel){
  if (!canvas || typeof window.qrcode !== 'function') {
    throw new Error('qr_generator_unavailable');
  }

  const qr = window.qrcode(0, 'M');
  qr.addData(value);
  qr.make();

  const context = canvas.getContext('2d', {alpha:false});
  if (!context) throw new Error('canvas_unavailable');

  const canvasSize = 640;
  const quietZoneModules = 4;
  const moduleCount = qr.getModuleCount();
  const cellSize = Math.floor(
    canvasSize / (moduleCount + quietZoneModules * 2)
  );
  const renderedSize = cellSize * moduleCount;
  const offset = Math.floor((canvasSize - renderedSize) / 2);

  canvas.width = canvasSize;
  canvas.height = canvasSize;
  context.imageSmoothingEnabled = false;
  context.fillStyle = '#ffffff';
  context.fillRect(0, 0, canvasSize, canvasSize);
  context.fillStyle = '#07100d';

  for (let row = 0; row < moduleCount; row += 1) {
    for (let column = 0; column < moduleCount; column += 1) {
      if (!qr.isDark(row, column)) continue;
      context.fillRect(
        offset + column * cellSize,
        offset + row * cellSize,
        cellSize,
        cellSize
      );
    }
  }

  canvas.setAttribute('aria-label', accessibleLabel);
}

function clearQrCanvas(canvas){
  if (!canvas) return;
  const context = canvas.getContext('2d', {alpha:false});
  if (!context) return;
  context.fillStyle = '#ffffff';
  context.fillRect(0, 0, canvas.width, canvas.height);
}

function initializePermanentQr(){
  if (permanentQrReady) return;

  const publicUrl = getPublicAppUrl();
  const state = document.getElementById('permanentQrState');
  document.getElementById('publicQrUrl').textContent = publicUrl;

  try {
    renderQrToCanvas(
      document.getElementById('permanentQrCanvas'),
      publicUrl,
      `QR Code permanente para abrir ${publicUrl}`
    );
    permanentQrReady = true;
    state.textContent = 'QR permanente pronto para baixar ou imprimir.';
  } catch (error) {
    state.textContent =
      'Não foi possível gerar o QR neste navegador. Use o endereço público exibido.';
    document.getElementById('btnDownloadPermanentQr').disabled = true;
    document.getElementById('btnPrintPermanentQr').disabled = true;
  }
}

async function copyTextValue(value, selectionTarget, successMessage){
  try {
    if (!navigator.clipboard || typeof navigator.clipboard.writeText !== 'function') {
      throw new Error('clipboard_unavailable');
    }
    await navigator.clipboard.writeText(value);
    showToast(successMessage);
  } catch (error) {
    const selection = window.getSelection();
    const range = document.createRange();
    range.selectNodeContents(selectionTarget);
    selection.removeAllRanges();
    selection.addRange(range);
    showToast(
      'Cópia automática indisponível. O conteúdo foi selecionado para cópia manual.',
      false,
      4200
    );
  }
}

function downloadQrCanvas(canvas, filename){
  if (!canvas || typeof canvas.toDataURL !== 'function') {
    showToast('A imagem do QR não está disponível.', false);
    return;
  }

  const downloadLink = document.createElement('a');
  downloadLink.href = canvas.toDataURL('image/png');
  downloadLink.download = filename;
  document.body.appendChild(downloadLink);
  downloadLink.click();
  downloadLink.remove();
}

function clearQrPrintSheet(){
  document.body.classList.remove('qr-printing');
  const sheet = document.getElementById('qrPrintSheet');
  sheet.setAttribute('aria-hidden', 'true');
  document.getElementById('qrPrintImage').removeAttribute('src');
}

function printQrCanvas(canvas, title, value, instruction){
  if (!canvas || typeof canvas.toDataURL !== 'function') {
    showToast('A imagem do QR não está disponível para impressão.', false);
    return;
  }

  document.getElementById('qrPrintImage').src = canvas.toDataURL('image/png');
  document.getElementById('qrPrintTitle').textContent = title;
  document.getElementById('qrPrintValue').textContent = value;
  document.getElementById('qrPrintInstruction').textContent = instruction;
  document.getElementById('qrPrintSheet').setAttribute('aria-hidden', 'false');
  document.body.classList.add('qr-printing');
  window.print();
  window.setTimeout(clearQrPrintSheet, 60000);
}

function copyPublicQrUrl(){
  const output = document.getElementById('publicQrUrl');
  const publicUrl = output.textContent.trim() || getPublicAppUrl();
  copyTextValue(publicUrl, output, 'Endereço público copiado.');
}

function downloadPermanentQr(){
  if (!permanentQrReady) {
    showToast('O QR permanente ainda não está disponível.', false);
    return;
  }
  downloadQrCanvas(
    document.getElementById('permanentQrCanvas'),
    'davez-acesso-permanente.png'
  );
}

function printPermanentQr(){
  if (!permanentQrReady) {
    showToast('O QR permanente ainda não está disponível.', false);
    return;
  }
  printQrCanvas(
    document.getElementById('permanentQrCanvas'),
    'Acesse o DaVez',
    getPublicAppUrl(),
    'Leia o QR e informe seu nome e código individual para fazer o check-in.'
  );
}

const TICKET_STATE_LABELS = Object.freeze({
  active:'Ativo',
  consumed:'Utilizado',
  expired:'Expirado',
  revoked:'Revogado',
  unknown:'Verificação indisponível'
});

function updateTicketActionAvailability(){
  const hasTicket = currentIssuedTicket !== null;
  const active = hasTicket && currentIssuedTicket.state === 'active';
  document.getElementById('btnCopyTicket').disabled = !active;
}

function setIssuedTicketState(state){
  const normalizedState = Object.prototype.hasOwnProperty.call(
    TICKET_STATE_LABELS,
    state
  )
    ? state
    : 'unknown';
  if (currentIssuedTicket) currentIssuedTicket.state = normalizedState;
  const stateElement = document.getElementById('issuedTicketState');
  stateElement.dataset.state = normalizedState;
  stateElement.textContent = TICKET_STATE_LABELS[normalizedState];
  updateTicketActionAvailability();
}

function updateIssuedTicketCountdown(){
  const countdown = document.getElementById('issuedTicketCountdown');
  if (!currentIssuedTicket) {
    countdown.textContent = '--:--';
    return;
  }

  if (currentIssuedTicket.state !== 'active') {
    countdown.textContent = 'Encerrado';
    return;
  }

  const remainingMilliseconds =
    currentIssuedTicket.expiresAt.getTime() - Date.now();
  if (!Number.isFinite(remainingMilliseconds) || remainingMilliseconds <= 0) {
    countdown.textContent = '00:00';
    setIssuedTicketState('expired');
    return;
  }

  const remainingSeconds = Math.ceil(remainingMilliseconds / 1000);
  const minutes = Math.floor(remainingSeconds / 60);
  const seconds = remainingSeconds % 60;
  countdown.textContent =
    `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
}

function clearIssuedTicket(){
  const result = document.getElementById('issuedTicketResult');
  currentIssuedTicket = null;
  document.getElementById('issuedAccessCode').textContent = '';
  document.getElementById('issuedTicketPurpose').textContent = 'Código emitido';
  document.getElementById('issuedTicketExpiry').textContent = '';
  document.getElementById('issuedTicketExpiry').removeAttribute('datetime');
  document.getElementById('issuedTicketCountdown').textContent = '--:--';
  document.getElementById('issuedTicketWarning').textContent = '';
  document.getElementById('individualQrCanvas').setAttribute(
    'aria-label',
    'QR Code individual ainda não emitido'
  );
  clearQrCanvas(document.getElementById('individualQrCanvas'));
  setIssuedTicketState('unknown');
  result.hidden = true;
  document.getElementById('individualQrEmpty').hidden = false;
}

function hideIssuedTicket(){
  clearIssuedTicket();
  document.getElementById('btnIssueCheckinTicket').focus();
}

function showIssuedTicket(data, purposeLabel){
  const accessCode = typeof data.access_code === 'string'
    ? data.access_code.trim()
    : '';
  if (!accessCode) {
    throw new AdminRequestError('O servidor não retornou o código individual.');
  }

  const expiry = new Date(String(data.expires_at || ''));
  if (Number.isNaN(expiry.getTime())) {
    throw new AdminRequestError('O servidor retornou uma validade inválida.');
  }

  const accessUrl = getIndividualAccessUrl(accessCode);
  let qrReady = false;
  try {
    renderQrToCanvas(
      document.getElementById('individualQrCanvas'),
      accessUrl,
      `QR Code individual válido até ${expiry.toLocaleString('pt-BR')}`
    );
    qrReady = true;
  } catch (error) {
    clearQrCanvas(document.getElementById('individualQrCanvas'));
  }

  currentIssuedTicket = {
    code:accessCode,
    accessUrl,
    expiresAt:expiry,
    state:'active',
    qrReady
  };

  // O botao e o resultado vivem na mesma aba (Chamada); nao trocamos de aba.
  const expiryText = expiry.toLocaleString('pt-BR', {
    dateStyle:'short',
    timeStyle:'short'
  });
  const result = document.getElementById('issuedTicketResult');
  document.getElementById('issuedTicketPurpose').textContent = purposeLabel;
  document.getElementById('issuedAccessCode').textContent = accessCode;
  const expiryElement = document.getElementById('issuedTicketExpiry');
  expiryElement.textContent = expiryText;
  expiryElement.dateTime = expiry.toISOString();
  document.getElementById('issuedTicketWarning').textContent = qrReady
    ? (typeof data.aviso === 'string'
        ? data.aviso
        : 'Exiba e entregue este código apenas uma vez.')
    : 'O código foi emitido, mas o QR não pôde ser renderizado. Entregue o código digitável.';
  document.getElementById('individualQrEmpty').hidden = true;
  setIssuedTicketState('active');
  updateIssuedTicketCountdown();
  result.hidden = false;
  result.focus();
}

async function refreshIndividualTicketStatus(){
  if (
    !currentIssuedTicket
    || ticketStatusChecking
    || !['active', 'unknown'].includes(currentIssuedTicket.state)
  ) {
    return;
  }

  ticketStatusChecking = true;
  const codeBeingChecked = currentIssuedTicket.code;
  try {
    const data = await fetchJsonAdmin('admin.php', {
      method:'POST',
      headers:{
        'Content-Type':'application/json',
        'X-CSRF-Token':CSRF_TOKEN
      },
      body:JSON.stringify({
        acao:'ticket_status',
        access_code:codeBeingChecked,
        _csrf:CSRF_TOKEN
      })
    });
    if (!currentIssuedTicket || currentIssuedTicket.code !== codeBeingChecked) {
      return;
    }
    setIssuedTicketState(
      typeof data.ticket_state === 'string'
        ? data.ticket_state
        : 'unknown'
    );
  } catch (error) {
    if (
      currentIssuedTicket
      && currentIssuedTicket.code === codeBeingChecked
      && !(error instanceof AdminAuthenticationRequiredError)
    ) {
      setIssuedTicketState('unknown');
    }
  } finally {
    ticketStatusChecking = false;
  }
}

async function issueIndividualTicket({
  action,
  id=null,
  button,
  purposeLabel
}){
  clearIssuedTicket();
  setButtonBusy(button, true);
  const payload = {
    acao:action,
    _csrf:CSRF_TOKEN
  };
  if (id !== null) payload.id = id;

  try {
    const data = await fetchJsonAdmin('admin.php', {
      method:'POST',
      headers:{
        'Content-Type':'application/json',
        'X-CSRF-Token':CSRF_TOKEN
      },
      body:JSON.stringify(payload)
    });
    if (!data.sucesso) {
      throw new AdminRequestError(
        getErrorMessage(data, 'Não foi possível emitir o código individual.')
      );
    }
    showIssuedTicket(data, purposeLabel);
    showToast('Código individual emitido. Entregue-o somente ao destinatário.');
  } catch (error) {
    if (!(error instanceof AdminAuthenticationRequiredError)) {
      showToast(error.message || 'Não foi possível emitir o código individual.', false);
    }
  } finally {
    setButtonBusy(button, false);
  }
}

async function issueCheckinTicket(){
  const button = document.getElementById('btnIssueCheckinTicket');
  await issueIndividualTicket({
    action:'issue_checkin_ticket',
    button,
    purposeLabel:'Código individual para novo check-in'
  });
}

async function issueRecoveryTicket(button, id){
  if (!Number.isInteger(id) || id < 1) {
    showToast('Check-in inválido para recovery.', false);
    return;
  }

  const item = button.closest('.queue-item');
  const name = item && item.querySelector('.nome')
    ? item.querySelector('.nome').textContent.trim()
    : `registro #${id}`;
  const confirmed = await openAdminDialog({
    title:'Emitir código de recovery?',
    message:`Um código individual será emitido para ${name}. Confirme o destinatário antes de entregar.`,
    confirmLabel:'Emitir recovery',
    cancelLabel:'Cancelar',
    tone:'danger'
  });
  if (!confirmed) return;

  await issueIndividualTicket({
    action:'issue_recovery_ticket',
    id,
    button,
    purposeLabel:`Código de recovery para ${name}`
  });
}

async function copyIssuedTicket(){
  const code = document.getElementById('issuedAccessCode').textContent.trim();
  if (
    !code
    || !currentIssuedTicket
    || currentIssuedTicket.state !== 'active'
  ) {
    showToast('Nenhum código ativo disponível para copiar.', false);
    return;
  }

  await copyTextValue(
    code,
    document.getElementById('issuedAccessCode'),
    'Código copiado. Compartilhe-o somente com o destinatário.'
  );
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
    // Não sobrescreve lat/lng/raio aqui: o polling de 12s apagaria o que o
    // admin está digitando na aba Configurações. Esses campos são carregados
    // por carregarConfig() ao abrir a aba e após salvar.
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
          <button type="button" class="mini-btn btn-secondary"
            data-action="issue-recovery" data-id="${id}">Emitir recovery</button>
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

let reportPage = 1;
let reportDateFrom = '';
let reportDateTo = '';

function parseUsDate(value){
  const normalized = String(value || '').trim();
  const match = /^(\d{1,2})\/(\d{1,2})\/(\d{4})$/.exec(normalized);
  if (!match) return '';
  const month = Number(match[1]);
  const day = Number(match[2]);
  const year = Number(match[3]);
  if (year < 2000 || year > 2100 || month < 1 || month > 12 || day < 1 || day > 31) {
    return '';
  }
  const date = new Date(Date.UTC(year, month - 1, day));
  if (
    date.getUTCFullYear() !== year
    || date.getUTCMonth() !== month - 1
    || date.getUTCDate() !== day
  ) {
    return '';
  }
  return `${String(year).padStart(4, '0')}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}

function formatIsoDateUs(value){
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value || ''));
  return match ? `${match[2]}/${match[3]}/${match[1]}` : '';
}

function formatSqlDateTimeUs(value){
  const match = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/.exec(String(value || ''));
  if (!match) return String(value || '');
  const date = `${match[2]}/${match[3]}/${match[1]}`;
  return match[4] ? `${date} ${match[4]}:${match[5]}` : date;
}

function readDateField(proxyId, required = false){
  const proxy = document.getElementById(proxyId);
  const textInput = document.querySelector(`[data-date-text="${proxyId}"]`);
  const raw = String(textInput?.value || '').trim();
  if (raw === '') {
    if (proxy) proxy.value = '';
    return required ? null : '';
  }
  const iso = parseUsDate(raw);
  if (!iso) {
    textInput?.setAttribute('aria-invalid', 'true');
    textInput?.focus();
    return null;
  }
  textInput?.removeAttribute('aria-invalid');
  if (proxy) proxy.value = iso;
  if (textInput) textInput.value = formatIsoDateUs(iso);
  return iso;
}

function clearDateField(proxyId){
  const proxy = document.getElementById(proxyId);
  const textInput = document.querySelector(`[data-date-text="${proxyId}"]`);
  if (proxy) proxy.value = '';
  if (textInput) {
    textInput.value = '';
    textInput.removeAttribute('aria-invalid');
  }
}

function initializeDateFields(){
  document.querySelectorAll('[data-date-picker]').forEach(button => {
    button.addEventListener('click', () => {
      const proxy = document.getElementById(button.dataset.datePicker || '');
      if (!proxy) return;
      try {
        if (typeof proxy.showPicker === 'function') proxy.showPicker();
        else { proxy.focus(); proxy.click(); }
      } catch (error) {
        proxy.focus();
      }
    });
  });

  document.querySelectorAll('input.native-date-proxy').forEach(proxy => {
    proxy.addEventListener('change', () => {
      const textInput = document.querySelector(`[data-date-text="${proxy.id}"]`);
      if (textInput) {
        textInput.value = formatIsoDateUs(proxy.value);
        textInput.removeAttribute('aria-invalid');
      }
    });
  });

  document.querySelectorAll('[data-date-text]').forEach(input => {
    input.addEventListener('blur', () => {
      if (!input.value.trim()) {
        clearDateField(input.dataset.dateText || '');
        return;
      }
      const iso = parseUsDate(input.value);
      if (iso) {
        input.value = formatIsoDateUs(iso);
        input.removeAttribute('aria-invalid');
        const proxy = document.getElementById(input.dataset.dateText || '');
        if (proxy) proxy.value = iso;
      }
    });
  });
}

function localTimeLabel(date = new Date()){
  if (!(date instanceof Date) || Number.isNaN(date.getTime())) return '--:--';
  return new Intl.DateTimeFormat('pt-BR', {
    hour:'2-digit', minute:'2-digit', second:'2-digit'
  }).format(date);
}

function setText(id, value){
  const element = document.getElementById(id);
  if (element) element.textContent = String(value);
}

function buildReportsPdfUrl(){
  const params = new URLSearchParams();
  if (reportDateFrom) params.set('date_from', reportDateFrom);
  if (reportDateTo) params.set('date_to', reportDateTo);
  const query = params.toString();
  return 'reports_pdf.php' + (query ? '?' + query : '');
}

function downloadReportsPdf(){
  window.location.assign(buildReportsPdfUrl());
}


function paginationMarkup(page, totalPages, total, pager){
  const safePage = Math.max(1, Number(page) || 1);
  const safeTotalPages = Math.max(1, Number(totalPages) || 1);
  const safeTotal = Math.max(0, Number(total) || 0);
  return `
    <span class="report-summary">Página ${safePage} de ${safeTotalPages} · ${safeTotal} registro(s)</span>
    <div class="pagination-actions">
      <button type="button" class="btn-secondary" data-pager="${pager}" data-page="${safePage - 1}"
        ${safePage <= 1 ? 'disabled' : ''}>Anterior</button>
      <button type="button" class="btn-secondary" data-pager="${pager}" data-page="${safePage + 1}"
        ${safePage >= safeTotalPages ? 'disabled' : ''}>Próxima</button>
    </div>`;
}

async function carregarRelatorios(page=reportPage){
  const box = document.getElementById('lastReportBox');
  const pagination = document.getElementById('reportPagination');
  reportPage = Math.max(1, Number(page) || 1);
  box.setAttribute('aria-busy', 'true');
  if (pagination) pagination.innerHTML = '';

  const params = new URLSearchParams({
    action:'listar_relatorios',
    page:String(reportPage),
    per_page:'15'
  });
  if (reportDateFrom) params.set('date_from', reportDateFrom);
  if (reportDateTo) params.set('date_to', reportDateTo);

  try {
    const data = await fetchJsonAdmin('admin.php?' + params.toString());
    const lista = Array.isArray(data.items) ? data.items : [];
    reportPage = Number(data.page) || reportPage;
    setText('reportsTotal', Number(data.total) || 0);
    setText('reportsPageSummary', `${Number(data.page) || 1} / ${Number(data.total_pages) || 1}`);
    setText(
      'reportsFilterSummary',
      reportDateFrom && reportDateTo ? `${formatIsoDateUs(reportDateFrom)} → ${formatIsoDateUs(reportDateTo)}` : 'Todos'
    );

    if (lista.length === 0) {
      box.innerHTML = renderState('Nenhum relatório encontrado para os filtros atuais.');
    } else {
      box.innerHTML = `
        <div class="table-scroll report-index-scroll" tabindex="0" role="region" aria-label="Lista paginada de relatórios">
          <table class="report-table report-index-table">
            <caption>Relatórios operacionais, 15 registros por página.</caption>
            <thead><tr>
              <th scope="col">ID</th>
              <th scope="col">Criado em</th>
              <th scope="col">Período</th>
              <th scope="col">Check-ins</th>
              <th scope="col">Únicos</th>
              <th scope="col">Fechados</th>
              <th scope="col">Status</th>
              <th scope="col">Ações</th>
            </tr></thead>
            <tbody>
              ${lista.map(r => `
                <tr>
                  <td><b>#${escapeHtml(r.id)}</b></td>
                  <td>${escapeHtml(formatSqlDateTimeUs(r.created_at))}</td>
                  <td>${escapeHtml(formatSqlDateTimeUs(r.periodo_inicio))}<br><span class="mini">até ${escapeHtml(formatSqlDateTimeUs(r.periodo_fim))}</span></td>
                  <td>${escapeHtml(r.total_checkins)}</td>
                  <td>${escapeHtml(r.motoboys_unicos)}</td>
                  <td>${escapeHtml(r.total_fechados)}</td>
                  <td><span class="report-status-badge">Pronto</span></td>
                  <td>
                    <div class="item-actions report-table-actions">
                      <button type="button" class="mini-btn btn-primary" data-action="view-report" data-id="${escapeHtml(r.id)}">Visualizar</button>
                      <button type="button" class="mini-btn btn-secondary" data-action="download-report" data-id="${escapeHtml(r.id)}">PDF</button>
                      <button type="button" class="mini-btn btn-danger" data-action="delete-report" data-id="${escapeHtml(r.id)}">Apagar</button>
                    </div>
                  </td>
                </tr>
              `).join('')}
            </tbody>
          </table>
        </div>
      `;
    }

    if (pagination) {
      pagination.innerHTML = paginationMarkup(
        data.page,
        data.total_pages,
        data.total,
        'reports'
      );
    }
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

function applyReportFilters(){
  const parsedFrom = readDateField('reportDateFrom', false);
  const parsedTo = readDateField('reportDateTo', false);
  if (parsedFrom === null || parsedTo === null) {
    showToast('Use o formato MM/DD/YYYY ou selecione a data no calendário.', false);
    return;
  }
  reportDateFrom = parsedFrom;
  reportDateTo = parsedTo;
  if ((reportDateFrom && !reportDateTo) || (!reportDateFrom && reportDateTo)) {
    showToast('Informe as duas datas ou deixe ambas vazias.', false);
    return;
  }
  if (reportDateFrom && reportDateTo && reportDateTo < reportDateFrom) {
    showToast('A data final não pode ser anterior à inicial.', false);
    return;
  }
  carregarRelatorios(1);
}

function clearReportFilters(){
  reportDateFrom = '';
  reportDateTo = '';
  clearDateField('reportDateFrom');
  clearDateField('reportDateTo');
  carregarRelatorios(1);
}

/* Dicionário de códigos de erro que o celular do motoboy relata. Traduz cada
   código em Motivo (o que aconteceu) e Solução (o que a equipe faz). */
const CLIENT_ERROR_INFO = {
  outside_allowed_area: {
    motivo: 'O motoboy está fora da área permitida da loja.',
    solucao: 'Peça para ele chegar mais perto da loja, dentro do raio configurado, e tentar de novo.'
  },
  invalid_access_code: {
    motivo: 'Código individual inválido ou expirado.',
    solucao: 'Gere um novo código para o motoboy na aba Chamada.'
  },
  invalid_checkin_data: {
    motivo: 'Nome, código ou localização inválidos no check-in.',
    solucao: 'Confira se o nome e o código foram digitados corretamente.'
  },
  checkin_already_exists: {
    motivo: 'Esse nome já fez check-in hoje.',
    solucao: 'Use "Emitir recovery" para o motoboy e peça que ele toque em "Recuperar acesso".'
  },
  checkin_closed: {
    motivo: 'O check-in do motoboy foi encerrado hoje.',
    solucao: 'Gere um novo código para ele na aba Chamada.'
  },
  not_checked_in: {
    motivo: 'O código ainda não fez check-in hoje.',
    solucao: 'Peça para o motoboy fazer o check-in com o nome antes de recuperar.'
  },
  queue_closed: {
    motivo: 'A chamada está fechada no momento.',
    solucao: 'Abra a chamada na aba Chamada.'
  },
  rate_limit_exceeded: {
    motivo: 'Muitas tentativas em pouco tempo.',
    solucao: 'Peça para aguardar cerca de 1 minuto e tentar de novo.'
  },
  already_waiting: {
    motivo: 'O motoboy já está na fila.',
    solucao: 'Não precisa entrar de novo; ele já está aguardando a vez.'
  },
  queue_busy: {
    motivo: 'A fila estava ocupada no momento.',
    solucao: 'Peça para tentar novamente em alguns segundos.'
  },
  queue_unavailable: {
    motivo: 'A fila ficou indisponível por um instante.',
    solucao: 'Peça para tentar novamente; se persistir, me avise.'
  },
  geofence_unavailable: {
    motivo: 'A validação de localização ficou indisponível.',
    solucao: 'Confira Latitude, Longitude e Raio na aba Configurações.'
  },
  settings_unavailable: {
    motivo: 'As configurações da loja ficaram indisponíveis.',
    solucao: 'Confira a aba Configurações e salve novamente as coordenadas.'
  },
  identity_upgrade_required: {
    motivo: 'O app do motoboy está com uma versão antiga em cache.',
    solucao: 'Peça para recarregar a página (puxar para atualizar) e tentar de novo.'
  },
  request_context_required: {
    motivo: 'A página do motoboy ficou aberta tempo demais.',
    solucao: 'Peça para recarregar a página e tentar de novo.'
  },
  network_error: {
    motivo: 'Falha de conexão do celular com o servidor.',
    solucao: 'Verifique a internet do motoboy e peça para tentar novamente.'
  },
  invalid_response: {
    motivo: 'O servidor devolveu uma resposta inesperada.',
    solucao: 'Peça para o motoboy atualizar a página e tentar de novo.'
  },
  provider_challenge: {
    motivo: 'O provedor de hospedagem pediu uma nova validação.',
    solucao: 'Peça para o motoboy atualizar a página e tentar de novo.'
  },
  geo_denied: {
    motivo: 'O motoboy negou a permissão de localização.',
    solucao: 'Peça para permitir o acesso à localização nas configurações do navegador e tentar de novo.'
  },
  geo_unavailable: {
    motivo: 'Não foi possível obter a localização (GPS).',
    solucao: 'Peça para ativar o GPS e tentar em local aberto.'
  },
  geo_timeout: {
    motivo: 'A localização demorou demais para responder.',
    solucao: 'Peça para aguardar o GPS estabilizar e tentar de novo.'
  },
  geo_unsupported: {
    motivo: 'O aparelho do motoboy não suporta localização.',
    solucao: 'Peça para usar um navegador/aparelho com GPS.'
  },
  geo_unknown: {
    motivo: 'Erro desconhecido ao obter a localização.',
    solucao: 'Peça para tentar novamente; se persistir, reiniciar o GPS.'
  }
};

const CLIENT_CONTEXT_LABEL = {
  checkin: 'Check-in',
  checkin_geo: 'Check-in (localização)',
  enter: 'Entrar na fila',
  enter_geo: 'Entrar na fila (localização)',
  recover: 'Recuperação de acesso',
  geo: 'Localização',
  app: 'App'
};

function renderClientErrorCard(ev){
  const dados = ev && ev.data && typeof ev.data === 'object' ? ev.data : {};
  const code = String(dados.client_code || '').toLowerCase();
  const info = CLIENT_ERROR_INFO[code] || {
    motivo: `Erro não catalogado (código: ${code || 'desconhecido'}).`,
    solucao: 'Anote o código e o horário e me avise para investigar.'
  };
  const contexto = CLIENT_CONTEXT_LABEL[String(dados.client_context || '')]
    || (dados.client_context ? String(dados.client_context) : '');
  const statusTxt = (dados.client_status !== undefined && dados.client_status !== null)
    ? ` · HTTP ${escapeHtml(String(dados.client_status))}`
    : '';
  const meta = [];
  if (contexto) meta.push(`Onde: ${escapeHtml(contexto)}`);
  if (code) meta.push(`Código: ${escapeHtml(code)}`);
  return `
    <article class="report-item log-client-error">
      <div class="report-copy">
        <b>Erro no celular do motoboy</b>
        <div class="mini">${escapeHtml(ev.time || '')}${statusTxt}</div>
        <div class="log-line"><span class="log-tag log-tag-motivo">Motivo</span> ${escapeHtml(info.motivo)}</div>
        <div class="log-line"><span class="log-tag log-tag-solucao">Solução</span> ${escapeHtml(info.solucao)}</div>
        ${meta.length ? `<div class="mini">${meta.join(' · ')}</div>` : ''}
      </div>
    </article>
  `;
}

async function carregarLogs(){
  const box = document.getElementById('supportLogBox');
  if (!box) return;
  box.setAttribute('aria-busy', 'true');
  try {
    const data = await fetchJsonAdmin("admin.php?action=logs");
    const eventos = Array.isArray(data.eventos) ? data.eventos : [];
    if (eventos.length === 0) {
      box.innerHTML = renderState('Nenhum registro de erro até agora.');
      return;
    }

    box.innerHTML = eventos.map(ev => {
      if (ev && ev.label === 'CLIENT_ERROR') {
        return renderClientErrorCard(ev);
      }
      const dados = ev && ev.data && typeof ev.data === 'object' ? ev.data : {};
      const metricas = Object.keys(dados)
        .map(chave => `${escapeHtml(chave)}: ${escapeHtml(dados[chave])}`)
        .join(' | ');
      return `
        <article class="report-item">
          <div class="report-copy">
            <b>${escapeHtml(ev.label || 'UNKNOWN_EVENT')}</b>
            <div class="mini">${escapeHtml(ev.time || '')}</div>
            ${metricas ? `<div class="mini">${metricas}</div>` : ''}
          </div>
        </article>
      `;
    }).join('');
  } catch (error) {
    if (!(error instanceof AdminAuthenticationRequiredError)) {
      box.innerHTML = renderState(
        error.message || 'Não foi possível carregar os registros.',
        'error'
      );
    }
  } finally {
    box.setAttribute('aria-busy', 'false');
  }
}

let rankingPeriodoAtual = 'dia';
let rankingPage = 1;
let rankingDateFrom = '';
let rankingDateTo = '';

function buildRankingPdfUrl(){
  const params = new URLSearchParams();
  if (rankingPeriodoAtual === 'custom') {
    params.set('date_from', rankingDateFrom);
    params.set('date_to', rankingDateTo);
  } else {
    params.set('periodo', rankingPeriodoAtual);
  }
  return 'ranking_pdf.php?' + params.toString();
}

function downloadRankingPdf(){
  if (rankingPeriodoAtual === 'custom' && (!rankingDateFrom || !rankingDateTo)) {
    showToast('Aplique um intervalo válido antes de gerar o PDF.', false);
    return;
  }
  window.location.assign(buildRankingPdfUrl());
}

function rankingLabelPeriodo(periodo){
  if (periodo === 'semana') return 'últimos 7 dias';
  if (periodo === 'mes') return 'últimos 30 dias';
  if (periodo === 'custom') return 'intervalo personalizado';
  return 'hoje';
}

function rankingEspera(segundos){
  if (segundos === null || segundos === undefined || !Number.isFinite(Number(segundos))) {
    return '—';
  }
  const total = Math.max(0, Math.round(Number(segundos)));
  if (total < 60) return `${total}s`;
  const min = Math.floor(total / 60);
  const seg = total % 60;
  return seg === 0 ? `${min}min` : `${min}min ${seg}s`;
}

function rankingSpark(serie){
  const pontos = Array.isArray(serie) ? serie.map(Number).filter(Number.isFinite) : [];
  if (pontos.length < 2) return '';
  const largura = 96;
  const altura = 28;
  const max = Math.max(...pontos, 1);
  const passo = largura / (pontos.length - 1);
  const coords = pontos.map((valor, indice) => {
    const x = Math.round(indice * passo);
    const y = Math.round(altura - 3 - (valor / max) * (altura - 6));
    return `${x},${y}`;
  }).join(' ');
  return `<svg class="ranking-spark" viewBox="0 0 ${largura} ${altura}" fill="none"
      stroke="currentColor" stroke-width="2" stroke-linecap="round"
      stroke-linejoin="round" role="img" aria-label="Evolução de entregas no período">
      <polyline points="${coords}"></polyline>
    </svg>`;
}

function rankingEvolucao(pct){
  if (pct === null || pct === undefined) {
    return '<span class="ranking-evo" data-dir="flat">— sem base</span>';
  }
  const valor = Number(pct);
  const dir = valor > 0 ? 'up' : (valor < 0 ? 'down' : 'flat');
  const seta = valor > 0 ? '▲' : (valor < 0 ? '▼' : '•');
  return `<span class="ranking-evo" data-dir="${dir}">${seta} ${Math.abs(valor)}%</span>`;
}

async function carregarRanking(periodo=rankingPeriodoAtual, page=1){
  const isPreset = ['dia', 'semana', 'mes'].includes(periodo);
  rankingPeriodoAtual = isPreset ? periodo : 'custom';
  rankingPage = Math.max(1, Number(page) || 1);
  const box = document.getElementById('rankingBox');
  const pagination = document.getElementById('rankingPagination');
  if (!box) return;

  document.querySelectorAll('.ranking-period').forEach(botao => {
    const ativo = isPreset && botao.dataset.periodo === rankingPeriodoAtual;
    botao.classList.toggle('active', ativo);
    botao.setAttribute('aria-pressed', ativo ? 'true' : 'false');
  });

  box.setAttribute('aria-busy', 'true');
  if (pagination) pagination.innerHTML = '';
  const faixa = document.getElementById('rankingRange');
  const params = new URLSearchParams({
    action:'ranking',
    periodo:rankingPeriodoAtual === 'custom' ? 'dia' : rankingPeriodoAtual,
    page:String(rankingPage),
    per_page:'25'
  });
  if (rankingPeriodoAtual === 'custom') {
    params.set('date_from', rankingDateFrom);
    params.set('date_to', rankingDateTo);
  }

  try {
    const data = await fetchJsonAdmin('admin.php?' + params.toString());
    const ranking = Array.isArray(data.ranking) ? data.ranking : [];
    rankingPage = Number(data.page) || rankingPage;
    setText('rankingTotal', Number(data.total) || 0);
    setText('rankingPageSummary', `${Number(data.page) || 1} / ${Number(data.total_pages) || 1}`);
    setText('rankingUpdatedAt', localTimeLabel());

    if (faixa) {
      faixa.textContent = `Período: ${rankingLabelPeriodo(data.periodo || rankingPeriodoAtual)}`
        + (data.inicio && data.fim ? ` (${formatIsoDateUs(data.inicio)} a ${formatIsoDateUs(data.fim)})` : '');
    }

    if (ranking.length === 0) {
      box.innerHTML = renderState(
        'Nenhuma entrega registrada neste período. O ranking preenche conforme os despachos acontecem.'
      );
    } else {
      const offset = (rankingPage - 1) * (Number(data.per_page) || 25);
      box.innerHTML = ranking.map((item, indice) => {
        const posicao = Number(item.position) || (offset + indice + 1);
        const media = Number(item.media_dia || 0);
        return `
          <article class="ranking-row" data-top="${posicao}" role="listitem">
            <span class="ranking-pos">${posicao}</span>
            <div class="ranking-main">
              <div class="ranking-name">${escapeHtml(item.nome)}</div>
              <div class="ranking-metrics">
                <span><b>${escapeHtml(item.entregas)}</b> entregas</span>
                <span><b>${escapeHtml(item.dias_ativos)}</b> dias ativos</span>
                <span><b>${escapeHtml(media)}</b>/dia</span>
                <span>espera <b>${escapeHtml(rankingEspera(item.espera_media_seg))}</b></span>
              </div>
            </div>
            <div class="ranking-side">
              ${rankingSpark(item.serie)}
              <span class="ranking-score">${escapeHtml(item.pontuacao)} <span>pts</span></span>
              ${rankingEvolucao(item.evolucao_pct)}
            </div>
          </article>
        `;
      }).join('');
    }

    if (pagination) {
      pagination.innerHTML = paginationMarkup(
        data.page,
        data.total_pages,
        data.total,
        'ranking'
      );
    }
  } catch (error) {
    if (!(error instanceof AdminAuthenticationRequiredError)) {
      box.innerHTML = renderState(
        error.message || 'Não foi possível carregar o ranking.',
        'error'
      );
    }
  } finally {
    box.setAttribute('aria-busy', 'false');
  }
}

function applyRankingRange(){
  const parsedFrom = readDateField('rankingDateFrom', false);
  const parsedTo = readDateField('rankingDateTo', false);
  if (parsedFrom === null || parsedTo === null) {
    showToast('Use o formato MM/DD/YYYY ou selecione a data no calendário.', false);
    return;
  }
  rankingDateFrom = parsedFrom;
  rankingDateTo = parsedTo;
  if (!rankingDateFrom || !rankingDateTo) {
    showToast('Informe a data inicial e a data final.', false);
    return;
  }
  if (rankingDateTo < rankingDateFrom) {
    showToast('A data final não pode ser anterior à inicial.', false);
    return;
  }
  carregarRanking('custom', 1);
}

function clearRankingRange(){
  rankingDateFrom = '';
  rankingDateTo = '';
  clearDateField('rankingDateFrom');
  clearDateField('rankingDateTo');
  carregarRanking('dia', 1);
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

    const ranking = Array.isArray(data.ranking) ? data.ranking : [];
    const rankRows = ranking.length === 0
      ? '<tr><td colspan="6">Nenhuma entrega despachada neste período.</td></tr>'
      : ranking.map(r => `
          <tr>
            <td>${escapeHtml(r.posicao ?? '')}º</td>
            <td>${escapeHtml(r.nome ?? '')}</td>
            <td>${escapeHtml(r.entregas ?? 0)}</td>
            <td>${escapeHtml(r.dias_ativos ?? 0)}</td>
            <td>${escapeHtml(rankingEspera(r.espera_media_seg))}</td>
            <td>${escapeHtml(r.score ?? 0)}</td>
          </tr>
        `).join('');

    box.innerHTML = `
      <div class="toolbar">
        <div>
          <b>Relatório #${escapeHtml(meta.id)}</b>
          <div class="mini">${escapeHtml(meta.periodo_inicio)} → ${escapeHtml(meta.periodo_fim)}</div>
          <div class="mini">Total: ${escapeHtml(meta.total_checkins)} | Únicos: ${escapeHtml(meta.motoboys_unicos)} | Fechados: ${escapeHtml(meta.total_fechados)}</div>
        </div>
        <div class="item-actions">
          <button type="button" class="mini-btn btn-primary" data-action="download-report" data-id="${escapeHtml(meta.id)}">Gerar Arquivo do Relatório</button>
          <button type="button" class="mini-btn btn-secondary" data-action="back-reports">Voltar</button>
        </div>
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
      <h3 class="report-rank-title">Ranking do período</h3>
      <p class="mini report-rank-help">Classificação por entregas despachadas no período deste relatório.</p>
      <div class="table-scroll" tabindex="0" role="region"
        aria-label="Ranking do relatório ${escapeHtml(meta.id)}">
        <table class="report-table">
          <caption>Ranking de motoboys por entregas no período do relatório.</caption>
          <thead>
            <tr>
              <th scope="col">#</th>
              <th scope="col">Motoboy</th>
              <th scope="col">Entregas</th>
              <th scope="col">Dias ativos</th>
              <th scope="col">Espera média</th>
              <th scope="col">Score</th>
            </tr>
          </thead>
          <tbody>${rankRows}</tbody>
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
    await carregarRelatorios(reportPage);
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
document.getElementById('btnIssueCheckinTicket').addEventListener('click', issueCheckinTicket);
document.getElementById('btnCopyTicket').addEventListener('click', copyIssuedTicket);
document.getElementById('btnRefreshTicket').addEventListener('click', issueCheckinTicket);
document.getElementById('btnHideTicket').addEventListener('click', hideIssuedTicket);
document.getElementById('btnDownloadPermanentQr').addEventListener('click', downloadPermanentQr);
document.getElementById('btnPrintPermanentQr').addEventListener('click', printPermanentQr);
document.getElementById('btnCopyPublicUrl').addEventListener('click', copyPublicQrUrl);
document.getElementById('btnShowManual').addEventListener('click', toggleManualBox);
document.getElementById('btnClear').addEventListener('click', limpar);
document.getElementById('btnRefreshDavez').addEventListener('click', ()=>carregarDaVez(true));
const btnRefreshLogs = document.getElementById('btnRefreshLogs');
if (btnRefreshLogs) btnRefreshLogs.addEventListener('click', carregarLogs);
document.querySelectorAll('.ranking-period').forEach(botao => {
  botao.addEventListener('click', () => {
    rankingDateFrom = '';
    rankingDateTo = '';
    carregarRanking(botao.dataset.periodo, 1);
  });
});
document.getElementById('btnApplyRankingRange').addEventListener('click', applyRankingRange);
document.getElementById('btnClearRankingRange').addEventListener('click', clearRankingRange);
document.getElementById('btnRankingPdf').addEventListener('click', downloadRankingPdf);
document.getElementById('btnApplyReportFilters').addEventListener('click', applyReportFilters);
document.getElementById('btnClearReportFilters').addEventListener('click', clearReportFilters);
document.getElementById('btnReportsPdf').addEventListener('click', downloadReportsPdf);
document.getElementById('rankingPagination').addEventListener('click', event => {
  const button = event.target.closest('button[data-pager="ranking"]');
  if (!button || button.disabled) return;
  carregarRanking(rankingPeriodoAtual, Number(button.dataset.page) || 1);
});
document.getElementById('reportPagination').addEventListener('click', event => {
  const button = event.target.closest('button[data-pager="reports"]');
  if (!button || button.disabled) return;
  carregarRelatorios(Number(button.dataset.page) || 1);
});
document.getElementById('manualForm').addEventListener('submit', event=>{
  event.preventDefault();
  addManual();
});
document.getElementById('configForm').addEventListener('submit', event=>{
  event.preventDefault();
  salvar();
});

function locateMe(){
  const btn = document.getElementById('btnLocateMe');
  const status = document.getElementById('locateStatus');
  if (!btn || !status) return;
  if (!('geolocation' in navigator)){
    status.textContent = 'Este dispositivo não suporta geolocalização.';
    return;
  }
  btn.setAttribute('aria-busy','true');
  status.textContent = 'Obtendo sua localização…';
  navigator.geolocation.getCurrentPosition(
    (pos) => {
      const acc = Math.round(pos.coords.accuracy || 0);
      const latEl = document.getElementById('lat');
      const lngEl = document.getElementById('lng');
      if (latEl) latEl.value = pos.coords.latitude.toFixed(7);
      if (lngEl) lngEl.value = pos.coords.longitude.toFixed(7);
      status.textContent = `Localização preenchida (precisão ~${acc} m). Revise e toque em Salvar configurações.`;
      btn.removeAttribute('aria-busy');
    },
    (err) => {
      let msg = 'Não foi possível obter a localização.';
      if (err && err.code === 1) msg = 'Permissão de localização negada. Autorize no navegador e tente novamente.';
      else if (err && err.code === 2) msg = 'Localização indisponível. Ative o GPS e tente em local aberto.';
      else if (err && err.code === 3) msg = 'A localização demorou demais. Tente novamente.';
      status.textContent = msg;
      btn.removeAttribute('aria-busy');
    },
    { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
  );
}
const btnLocateMe = document.getElementById('btnLocateMe');
if (btnLocateMe) btnLocateMe.addEventListener('click', locateMe);

document.getElementById('lista').addEventListener('click', event=>{
  const button = event.target.closest('button[data-action]');
  if (!button) return;
  const action = button.dataset.action;
  if (action === 'toggle-close') {
    toggleClose(button, Number.parseInt(button.dataset.id || '0', 10));
  }
  if (action === 'issue-recovery') {
    issueRecoveryTicket(button, Number.parseInt(button.dataset.id || '0', 10));
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
  if (button.dataset.action === 'download-report' && id > 0) {
    window.location.assign('report_pdf.php?id=' + encodeURIComponent(id));
  }
  if (button.dataset.action === 'delete-report') apagarRelatorio(id);
  if (button.dataset.action === 'back-reports') carregarRelatorios(reportPage);
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
window.addEventListener('afterprint', clearQrPrintSheet);

async function syncServerClock(){
  const chip = document.getElementById('serverClockChip');
  if (!chip) return;
  try {
    const data = await fetchJsonAdmin('admin.php?action=server_time');
    const serverEpoch = Number(data.epoch_ms);
    if (!Number.isFinite(serverEpoch)) throw new Error('Relógio inválido.');
    const driftSeconds = Math.round((Date.now() - serverEpoch) / 1000);
    const driftAbs = Math.abs(driftSeconds);
    chip.dataset.state = driftAbs <= 90 ? 'ok' : 'warn';
    const status = driftAbs <= 90 ? 'sincronizado' : `diferença ${driftAbs}s`;
    chip.innerHTML = `Relógio <strong>${escapeHtml(localTimeLabel(new Date(serverEpoch)))} · ${escapeHtml(status)}</strong>`;
    chip.title = `Servidor: ${data.iso || ''} | Fuso: ${data.timezone || ''}`;
  } catch (error) {
    chip.dataset.state = 'warn';
    chip.innerHTML = 'Relógio <strong>indisponível</strong>';
  }
}

setInterval(carregar, 12000);
setInterval(syncServerClock, 60000);
setInterval(updateIssuedTicketCountdown, 1000);
setInterval(refreshIndividualTicketStatus, 5000);
initializeDateFields();
initializePermanentQr();
clearIssuedTicket();
initMainSortable();
syncServerClock();
carregar();
carregarRelatorios();
</script>
</body>
</html>
