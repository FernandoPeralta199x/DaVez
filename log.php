<?php
function log_event($label, $data = []) {
  $linha = [
    'time' => date('Y-m-d H:i:s'),
    'ip'   => $_SERVER['REMOTE_ADDR'] ?? '',
    'ua'   => $_SERVER['HTTP_USER_AGENT'] ?? '',
    'label'=> $label,
    'data' => $data
  ];

  $dir = __DIR__ . '/logs';
  $file = $dir . '/checkin.log';

  if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
  }

  @file_put_contents(
    $file,
    json_encode($linha, JSON_UNESCAPED_UNICODE) . PHP_EOL,
    FILE_APPEND
  );
}
