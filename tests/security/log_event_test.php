<?php

require_once dirname(__DIR__, 2) . '/log.php';

function fail_test($message, $logFile) {
  if (is_file($logFile)) {
    @unlink($logFile);
  }

  fwrite(STDERR, $message . PHP_EOL);
  exit(1);
}

$logFile = tempnam(sys_get_temp_dir(), 'davez-log-');

if ($logFile === false) {
  fwrite(STDERR, 'Não foi possível criar o arquivo temporário.' . PHP_EOL);
  exit(1);
}

putenv('APP_LOG_PATH=' . $logFile);

$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
$_SERVER['HTTP_USER_AGENT'] = 'Sensitive User Agent';

$secretToken = 'token-que-nao-pode-ser-gravado';
$personName = 'Nome que não pode ser gravado';

log_event('SECURITY_TEST', [
  'post' => [
    'nome' => $personName,
    'token' => $secretToken,
  ],
  'token_recebido' => $secretToken,
  'token_esperado' => $secretToken,
  'nome' => $personName,
  'client_id' => 'identificador-privado',
  'lat' => -23.5505,
  'lng' => -46.6333,
  'mysql_error' => 'erro interno privado',
  'ordem' => '7',
  'distancia_m' => 12.5,
  'client_code' => 'outside_allowed_area',
  'client_context' => 'contexto inválido!',
  'client_status' => 403,
]);

$rawLog = file_get_contents($logFile);

if ($rawLog === false || trim($rawLog) === '') {
  fail_test('O logger não gravou o evento de teste.', $logFile);
}

foreach ([
  $secretToken,
  $personName,
  'identificador-privado',
  '203.0.113.10',
  'Sensitive User Agent',
  'erro interno privado',
  'contexto inválido!',
] as $forbiddenValue) {
  if (strpos($rawLog, $forbiddenValue) !== false) {
    fail_test('O logger persistiu um valor sensível.', $logFile);
  }
}

$decodedLog = json_decode(trim($rawLog), true);

if (!is_array($decodedLog)) {
  fail_test('O evento gravado não é um JSON válido.', $logFile);
}

if (($decodedLog['label'] ?? '') !== 'SECURITY_TEST') {
  fail_test('O label operacional não foi preservado.', $logFile);
}

if (($decodedLog['data']['ordem'] ?? null) !== 7) {
  fail_test('O campo operacional ordem não foi preservado.', $logFile);
}

if (($decodedLog['data']['distancia_m'] ?? null) !== 12.5) {
  fail_test('O campo operacional distancia_m não foi preservado.', $logFile);
}

if (($decodedLog['data']['client_code'] ?? null) !== 'outside_allowed_area') {
  fail_test('O código de erro do cliente (slug válido) não foi preservado.', $logFile);
}

if (($decodedLog['data']['client_status'] ?? null) !== 403) {
  fail_test('O status HTTP do erro do cliente não foi preservado.', $logFile);
}

if (array_key_exists('client_context', $decodedLog['data'] ?? [])) {
  fail_test('Um contexto de cliente malformado foi persistido.', $logFile);
}

// --- rotação: acima do teto configurado o arquivo é rotacionado para .1 ---
putenv('APP_LOG_MAX_BYTES=1024');
$rotated = false;
for ($i = 0; $i < 40; $i++) {
  log_event('ROTATION_TEST', ['ordem' => $i]);
  if (is_file($logFile . '.1')) {
    $rotated = true;
    break;
  }
}
putenv('APP_LOG_MAX_BYTES');

if (!$rotated) {
  @unlink($logFile . '.1');
  fail_test('O log não rotacionou ao exceder o teto configurado.', $logFile);
}

clearstatcache(true, $logFile);
if (filesize($logFile) > 1024) {
  @unlink($logFile . '.1');
  fail_test('O log atual não foi reduzido após a rotação.', $logFile);
}

@unlink($logFile . '.1');
@unlink($logFile);
putenv('APP_LOG_PATH');

fwrite(STDOUT, 'log_event_test: OK' . PHP_EOL);
