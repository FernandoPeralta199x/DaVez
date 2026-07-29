<?php
function sanitize_log_data($data) {
  if (!is_array($data)) {
    return [];
  }

  $allowedKeys = [
    'chamada_aberta',
    'raio',
    'distancia_m',
    'raio_m',
    'dist_m',
    'ordem',
    'pos',
    'got',
    'error_type',
    'error_line',
  ];

  $safeData = [];

  foreach ($allowedKeys as $key) {
    if (!array_key_exists($key, $data)) {
      continue;
    }

    $value = $data[$key];

    if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
      $safeData[$key] = $value;
      continue;
    }

    if (is_string($value) && is_numeric($value)) {
      $safeData[$key] = $value + 0;
    }
  }

  return $safeData;
}

function log_event($label, $data = []) {
  $safeLabel = preg_replace('/[^A-Z0-9_.-]/i', '_', (string) $label);
  $safeLabel = substr($safeLabel ?: 'UNKNOWN_EVENT', 0, 80);

  $linha = [
    'time' => date('Y-m-d H:i:s'),
    'label' => $safeLabel,
    'data' => sanitize_log_data($data),
  ];

  $configuredFile = getenv('APP_LOG_PATH');
  $file = is_string($configuredFile) && trim($configuredFile) !== ''
    ? $configuredFile
    : __DIR__ . '/logs/checkin.log';
  $dir = dirname($file);

  if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
  }

  $encodedLine = json_encode($linha, JSON_UNESCAPED_UNICODE);

  if ($encodedLine === false) {
    return;
  }

  @file_put_contents(
    $file,
    $encodedLine . PHP_EOL,
    FILE_APPEND | LOCK_EX
  );
}
