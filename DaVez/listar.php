<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Security/Bootstrap.php';
require_once __DIR__ . '/../src/Security/PublicIdentityAuth.php';
require_once __DIR__ . '/../src/Domain/OperationalCycle.php';
require_once __DIR__ . '/../src/Domain/OperationalContext.php';
require_once __DIR__ . '/../src/Http/PublicIdentityView.php';
require_once __DIR__ . '/../src/Database/bootstrap.php';

davez_install_safe_exception_handler();
davez_require_http_method('GET');

// A página pública consulta esta rota a cada cinco segundos e os dispositivos
// costumam compartilhar o IP da loja. O teto é alto o bastante para dezenas de
// aparelhos simultâneos e ainda assim corta coleta automatizada agressiva.
try {
    $listRate = davez_rate_limit_consume(
        'public-queue-list-v2',
        davez_rate_limit_request_subject(),
        600,
        60
    );
} catch (RuntimeException $exception) {
    davez_send_error(
        'security_control_unavailable',
        'Serviço temporariamente indisponível.',
        503
    );
}

if (!$listRate['allowed']) {
    header('Retry-After: ' . $listRate['retry_after']);
    davez_send_error(
        'rate_limit_exceeded',
        'Muitas consultas. Aguarde e tente novamente.',
        429
    );
}

include __DIR__ . '/../config.php';

$operationalCycle = \DaVez\Domain\OperationalCycle::fromEnvironment();
date_default_timezone_set($operationalCycle->timezone()->getName());
davez_configure_operational_database_timezone($conn, $operationalCycle);
$operationalContext = new \DaVez\Domain\OperationalContext(
    $operationalCycle
);
$operationalDate = $operationalContext->date();

try {
    $identity = davez_authenticated_public_identity(
        $conn,
        $operationalContext
    );
    $summary = davez_public_queue_summary(
        $conn,
        $operationalDate
    );
    $me = null;

    if ($identity !== null) {
        $identityView = davez_public_identity_me(
            $conn,
            $identity,
            $operationalContext
        );
        $me = $identityView['davez'];
    }
} catch (Throwable $exception) {
    davez_send_error(
        'queue_unavailable',
        'Fila temporariamente indisponível.',
        500
    );
}

davez_send_json([
    'ok' => true,
    'identity_version' => 2,
    'operational_date' => $operationalDate,
    'next' => $summary['next'],
    'me' => $me,
    'counts' => $summary['counts'],
]);
