<?php
function log_path_is_absolute($path) {
  return str_starts_with($path, '/')
    || str_starts_with($path, '\\\\')
    || preg_match('/\A[A-Za-z]:[\\\\\/]/', $path) === 1;
}

function log_normalize_path($path) {
  $normalized = rtrim(str_replace('\\', '/', $path), '/');
  return DIRECTORY_SEPARATOR === '\\' ? strtolower($normalized) : $normalized;
}

function log_path_is_within($candidate, $parent) {
  $candidate = log_normalize_path($candidate);
  $parent = log_normalize_path($parent);

  return $candidate === $parent || str_starts_with($candidate, $parent . '/');
}

function resolve_private_log_file() {
  $configuredFile = getenv('APP_LOG_PATH');
  $file = is_string($configuredFile) ? trim($configuredFile) : '';

  if ($file === '' || !log_path_is_absolute($file) || is_link($file)) {
    return null;
  }

  $dir = dirname($file);
  if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
    return null;
  }

  if (is_link($dir)) {
    return null;
  }

  $realDirectory = realpath($dir);
  if ($realDirectory === false || !is_writable($realDirectory)) {
    return null;
  }

  $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
  $realDocumentRoot = is_string($documentRoot) && $documentRoot !== ''
    ? realpath($documentRoot)
    : false;

  if (
    $realDocumentRoot !== false
    && log_path_is_within($realDirectory, $realDocumentRoot)
  ) {
    return null;
  }

  @chmod($realDirectory, 0700);

  return $realDirectory . DIRECTORY_SEPARATOR . basename($file);
}

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
    'client_status',
  ];

  // Chaves de rótulo curto (enum): aceitam somente slugs seguros [a-z0-9_],
  // nunca texto livre. É o que permite registrar o código de erro enviado
  // pelo celular do motoboy sem abrir espaço para injeção ou dados pessoais.
  $slugKeys = [
    'client_code' => 40,
    'client_context' => 20,
  ];

  $safeData = [];

  foreach ($slugKeys as $key => $maxLength) {
    if (!array_key_exists($key, $data)) {
      continue;
    }

    $value = $data[$key];

    if (
      is_string($value)
      && preg_match('/\A[a-z0-9_]{1,' . $maxLength . '}\z/', $value) === 1
    ) {
      $safeData[$key] = $value;
    }
  }

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

  $file = resolve_private_log_file();
  if ($file === null) {
    error_log('[DAVEZ_EVENT] ' . $safeLabel);
    return;
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

/**
 * Lê os eventos mais recentes já sanitizados, do mais novo para o mais antigo.
 *
 * Reaproveita a mesma resolução de caminho privado da escrita: o arquivo vive
 * fora do web root e cada linha já passou por sanitize_log_data(), então não há
 * dado pessoal, caminho ou detalhe interno para expor.
 *
 * @return list<array{time: string, label: string, data: array<string, mixed>}>
 */
function read_recent_log_events($limit = 100) {
  $limit = is_int($limit) ? $limit : (int) $limit;
  if ($limit < 1) {
    $limit = 1;
  }
  if ($limit > 500) {
    $limit = 500;
  }

  $file = resolve_private_log_file();
  if ($file === null || !is_file($file)) {
    return [];
  }

  $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  if ($lines === false) {
    return [];
  }

  $recent = array_slice($lines, -$limit);
  $events = [];

  foreach ($recent as $line) {
    $decoded = json_decode($line, true);
    if (!is_array($decoded)) {
      continue;
    }

    $events[] = [
      'time' => isset($decoded['time']) && is_string($decoded['time'])
        ? $decoded['time']
        : '',
      'label' => isset($decoded['label']) && is_string($decoded['label'])
        ? $decoded['label']
        : 'UNKNOWN_EVENT',
      'data' => isset($decoded['data']) && is_array($decoded['data'])
        ? $decoded['data']
        : [],
    ];
  }

  return array_reverse($events);
}
