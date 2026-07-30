<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Security/Bootstrap.php';
require_once __DIR__ . '/../src/Domain/OperationalCycle.php';
require_once __DIR__ . '/../src/Domain/OperationalContext.php';

davez_install_safe_exception_handler();
davez_require_http_method('GET');
davez_require_admin();

include __DIR__ . '/../config.php';

date_default_timezone_set('America/Sao_Paulo');
$operationalContext = new \DaVez\Domain\OperationalContext(
    new \DaVez\Domain\OperationalCycle()
);
$operationalDate = $operationalContext->date();

$statement = $conn->prepare(
    "SELECT id, checkin_id, client_id, nome, entered_at,
            status, last_action_at, ordem
     FROM fila_da_vez
     WHERE dia=?
     ORDER BY
       CASE WHEN status='na_fila' THEN 0 ELSE 1 END,
       ordem ASC,
       entered_at ASC"
);

if (!$statement) {
    davez_send_error(
        'admin_queue_unavailable',
        'Fila administrativa temporariamente indisponível.',
        500
    );
}

$statement->bind_param('s', $operationalDate);

if (!$statement->execute()) {
    $statement->close();
    davez_send_error(
        'admin_queue_unavailable',
        'Fila administrativa temporariamente indisponível.',
        500
    );
}

$result = $statement->get_result();
$queue = [];
$next = null;

while ($row = $result->fetch_assoc()) {
    $queue[] = $row;

    if ($next === null && ($row['status'] ?? '') === 'na_fila') {
        $next = $row;
    }
}

$statement->close();

davez_send_json([
    'ok' => true,
    'dia' => $operationalDate,
    'da_vez' => $next,
    'fila' => $queue,
]);
