<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Security/Bootstrap.php';
require_once __DIR__ . '/../src/Security/PublicIdentityAuth.php';
require_once __DIR__ . '/../src/Domain/OperationalCycle.php';
require_once __DIR__ . '/../src/Domain/OperationalContext.php';
require_once __DIR__ . '/../src/Http/PublicIdentityView.php';

davez_install_safe_exception_handler();
davez_require_http_method('GET');

include __DIR__ . '/../config.php';

date_default_timezone_set('America/Sao_Paulo');
$conn->query("SET time_zone = '-03:00'");
$operationalContext = new \DaVez\Domain\OperationalContext(
    new \DaVez\Domain\OperationalCycle()
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
