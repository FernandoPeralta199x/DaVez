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

@unlink($logFile);
putenv('APP_LOG_PATH');

fwrite(STDOUT, 'log_event_test: OK' . PHP_EOL);
