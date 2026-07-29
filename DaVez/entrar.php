<?php
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

include_once __DIR__ . "/../config.php";
include_once __DIR__ . "/../log.php";

header('Content-Type: text/plain; charset=utf-8');

error_reporting(E_ALL);
ini_set('display_errors', 0);
date_default_timezone_set('America/Sao_Paulo');

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

function get_operational_bounds(?DateTime $ref = null){
  $tz = new DateTimeZone('America/Sao_Paulo');
  $now = $ref ? clone $ref : new DateTime('now', $tz);

  $start = clone $now;
  $start->setTime(6, 0, 0);

  if ((int)$now->format('H') < 6) {
    $start->modify('-1 day');
  }

  $end = clone $start;
  $end->modify('+1 day');

  return [$start, $end];
}

function get_operational_date(?DateTime $ref = null){
  [$start, $end] = get_operational_bounds($ref);
  return $start->format('Y-m-d');
}

// ===== Token em ciclo de 3 dias, virando às 06:00 =====
function generate_token_code(){
  return strtoupper(substr(md5(uniqid('', true)), 0, 6));
}

function get_token_cycle_info(array $settings){
  $tz = new DateTimeZone('America/Sao_Paulo');
  $opDate = get_operational_date();

  $tokenData = trim((string)($settings['token_data'] ?? ''));
  $token = trim((string)($settings['token'] ?? ''));

  if ($tokenData === '') {
    $tokenData = $opDate;
  }

  $cycleStart = DateTime::createFromFormat('Y-m-d H:i:s', $tokenData . ' 06:00:00', $tz);
  if (!$cycleStart) {
    $cycleStart = new DateTime($opDate . ' 06:00:00', $tz);
  }

  $now = new DateTime('now', $tz);
  $cycleEnd = clone $cycleStart;
  $cycleEnd->modify('+3 days');

  $needsRotate = ($token === '');
  if ($now >= $cycleEnd) {
    $needsRotate = true;
  }

  return [
    'needs_rotate' => $needsRotate,
    'cycle_start' => $cycleStart,
    'cycle_end' => $cycleEnd,
    'base_date' => $cycleStart->format('Y-m-d')
  ];
}

function ensure_token_cycle($conn){
  $sRes = $conn->query("SELECT * FROM settings WHERE id=1 LIMIT 1");
  if (!$sRes) {
    throw new Exception("Erro ao ler settings: " . $conn->error);
  }

  $s = $sRes->fetch_assoc();
  if (!$s) {
    throw new Exception("Settings não configurado");
  }

  $info = get_token_cycle_info($s);

  if ($info['needs_rotate']) {
    $novoToken = generate_token_code();
    $novaBaseData = get_operational_date();

    $stmt = $conn->prepare("UPDATE settings SET token=?, token_data=? WHERE id=1");
    if (!$stmt) {
      throw new Exception("Prepare update token falhou: " . $conn->error);
    }
    $stmt->bind_param("ss", $novoToken, $novaBaseData);
    $stmt->execute();
    $stmt->close();

    $s['token'] = $novoToken;
    $s['token_data'] = $novaBaseData;
    $info = get_token_cycle_info($s);
  }

  $s['token_cycle_start'] = $info['cycle_start']->format('Y-m-d H:i:s');
  $s['token_cycle_end'] = $info['cycle_end']->format('Y-m-d H:i:s');

  return $s;
}

// ===== Distância (Haversine) =====
function haversine_m($lat1, $lng1, $lat2, $lng2){
  $R = 6371000;
  $phi1 = deg2rad($lat1);
  $phi2 = deg2rad($lat2);
  $dphi = deg2rad($lat2 - $lat1);
  $dl = deg2rad($lng2 - $lng1);
  $a = sin($dphi/2)*sin($dphi/2) + cos($phi1)*cos($phi2)*sin($dl/2)*sin($dl/2);
  $c = 2 * atan2(sqrt($a), sqrt(1-$a));
  return $R * $c;
}

log_event("DAVEZ_ENTRAR_START");

$nome     = trim($_POST['nome'] ?? '');
$token    = trim($_POST['token'] ?? '');
$lat      = floatval($_POST['lat'] ?? 0);
$lng      = floatval($_POST['lng'] ?? 0);
$clientId = $_POST['client_id'] ?? ($_COOKIE['cid'] ?? '');

[$opStart, $opEnd] = get_operational_bounds();
$opStartStr = $opStart->format('Y-m-d H:i:s');
$opEndStr   = $opEnd->format('Y-m-d H:i:s');
$dia        = get_operational_date();

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
  $s = ensure_token_cycle($conn);
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
  $clientId = md5(uniqid('', true));
  log_event("DAVEZ_CID_GERADO");
} else {
  log_event("DAVEZ_CID_OK");
}

setcookie('cid', $clientId, [
  'expires' => time() + 60*60*24*365,
  'path' => '/',
  'httponly' => false,
  'samesite' => 'Lax'
]);

$dist = haversine_m($latBase, $lngBase, $lat, $lng);
log_event("DAVEZ_DIST", ["dist_m" => $dist, "raio" => $raio]);

if ($raio > 0 && $dist > $raio) {
  http_response_code(403);
  die("Você está fora do raio permitido");
}

// cria tabela se não existir (safe)
$conn->query("CREATE TABLE IF NOT EXISTS fila_da_vez (
  id INT AUTO_INCREMENT PRIMARY KEY,
  dia DATE NOT NULL,
  client_id VARCHAR(64) NOT NULL,
  nome VARCHAR(120) NOT NULL,
  entered_at DATETIME NOT NULL,
  ordem INT NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'na_fila',
  last_action_at DATETIME DEFAULT NULL,
  UNIQUE KEY uniq_dia_client (dia, client_id),
  INDEX idx_dia_status (dia, status),
  INDEX idx_dia_ordem (dia, ordem),
  INDEX idx_dia_entered (dia, entered_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// garante coluna ordem se a tabela já existia antes
$colCheck = $conn->query("SHOW COLUMNS FROM fila_da_vez LIKE 'ordem'");
if ($colCheck && $colCheck->num_rows === 0) {
  $conn->query("ALTER TABLE fila_da_vez ADD COLUMN ordem INT NOT NULL DEFAULT 0 AFTER entered_at");
  $conn->query("ALTER TABLE fila_da_vez ADD INDEX idx_dia_ordem (dia, ordem)");
}

/* =====================================================
   BLOQUEIO DE NOME DUPLICADO NA FILA DA VEZ
===================================================== */
$nomeCheck = trim(mb_strtolower($nome));

$checkNome = $conn->prepare("
  SELECT id, status, client_id
  FROM fila_da_vez
  WHERE dia = ?
    AND LOWER(TRIM(nome)) = ?
    AND status = 'na_fila'
  LIMIT 1
");

if (!$checkNome) {
  log_event("DAVEZ_ERRO_PREP_DUP_NOME");
  http_response_code(500);
  die("Erro interno (validação nome)");
}

$checkNome->bind_param("ss", $dia, $nomeCheck);
$checkNome->execute();

$resNome = $checkNome->get_result();
$rowNome = $resNome ? $resNome->fetch_assoc() : null;
$checkNome->close();

if ($rowNome) {
  http_response_code(409);
  die("Este motoboy já está aguardando na fila da vez.");
}

// próxima ordem disponível
$nextOrdem = 1;
$qOrd = $conn->prepare("SELECT COALESCE(MAX(ordem), 0) + 1 AS prox FROM fila_da_vez WHERE dia=?");
if ($qOrd) {
  $qOrd->bind_param("s", $dia);
  $qOrd->execute();
  $rOrd = $qOrd->get_result();
  $rowOrd = $rOrd ? $rOrd->fetch_assoc() : null;
  if ($rowOrd && isset($rowOrd['prox'])) {
    $nextOrdem = (int)$rowOrd['prox'];
  }
  $qOrd->close();
}

// insere ou move pro final
$stmt = $conn->prepare("
  INSERT INTO fila_da_vez (dia, client_id, nome, entered_at, ordem, status, last_action_at)
  VALUES (?, ?, ?, NOW(), ?, 'na_fila', NOW())
  ON DUPLICATE KEY UPDATE
    nome=VALUES(nome),
    entered_at=NOW(),
    ordem=VALUES(ordem),
    status='na_fila',
    last_action_at=NOW()
");

if (!$stmt) {
  log_event("DAVEZ_ERRO_PREPARE");
  http_response_code(500);
  die("Erro interno");
}

$stmt->bind_param("sssi", $dia, $clientId, $nome, $nextOrdem);
$stmt->execute();

if ($stmt->affected_rows <= 0) {
  log_event("DAVEZ_ERRO_INSERT");
  http_response_code(500);
  die("Não foi possível entrar na fila");
}

$stmt->close();

log_event("DAVEZ_OK", [
  "ordem" => $nextOrdem
]);

echo "Ok, você entrou na fila da vez.";
