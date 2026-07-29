<?php
require_once __DIR__ . '/src/Security/Bootstrap.php';
require_once __DIR__ . '/src/Domain/OperationalCycle.php';
require_once __DIR__ . '/src/Domain/OperationalContext.php';
require_once __DIR__ . '/src/Database/bootstrap.php';
davez_install_safe_exception_handler();
davez_require_http_method('GET');
davez_bootstrap_public_request_context();
include_once __DIR__ . "/config.php";

date_default_timezone_set('America/Sao_Paulo');
$operationalContext = new \DaVez\Domain\OperationalContext(
  new \DaVez\Domain\OperationalCycle()
);

try {
  $data = davez_settings_token_cycle($conn)->loadAndRotate(
    $operationalContext
  );

  davez_send_json([
    'ok' => true,
    'token_data' => $data['token_data'],
    'token_cycle_start' => $data['token_cycle_start'],
    'token_cycle_end' => $data['token_cycle_end'],
    'operational_date' => $data['operational_date']
  ]);
} catch (Throwable $exception) {
  davez_send_error(
    'settings_unavailable',
    'Configurações temporariamente indisponíveis.',
    500
  );
}
