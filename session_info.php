<?php
include_once __DIR__ . "/config.php";
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
  $res = $conn->query("SELECT * FROM settings WHERE id=1 LIMIT 1");
  if (!$res) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'err'=>'Erro ao ler settings'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $s = $res->fetch_assoc();
  if (!$s) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'err'=>'Settings não configurado'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $info = get_token_cycle_info($s);

  if ($info['needs_rotate']) {
    $novoToken = generate_token_code();
    $novaBaseData = get_operational_date();

    $stmt = $conn->prepare("UPDATE settings SET token=?, token_data=? WHERE id=1");
    $stmt->bind_param("ss", $novoToken, $novaBaseData);
    $stmt->execute();

    $s['token'] = $novoToken;
    $s['token_data'] = $novaBaseData;
    $info = get_token_cycle_info($s);
  }

  return [
    'token_data' => $s['token_data'] ?? '',
    'token_cycle_start' => $info['cycle_start']->format('Y-m-d H:i:s'),
    'token_cycle_end' => $info['cycle_end']->format('Y-m-d H:i:s'),
    'operational_date' => get_operational_date()
  ];
}

$data = ensure_token_cycle($conn);

echo json_encode([
  'ok' => true,
  'token_data' => $data['token_data'],
  'token_cycle_start' => $data['token_cycle_start'],
  'token_cycle_end' => $data['token_cycle_end'],
  'operational_date' => $data['operational_date']
], JSON_UNESCAPED_UNICODE);