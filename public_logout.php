<?php

declare(strict_types=1);

require_once __DIR__ . '/src/Security/Bootstrap.php';
require_once __DIR__ . '/src/Security/PublicIdentityAuth.php';
require_once __DIR__ . '/src/Domain/OperationalCycle.php';
require_once __DIR__ . '/src/Domain/OperationalContext.php';
require_once __DIR__ . '/src/Database/bootstrap.php';

davez_install_safe_exception_handler();
davez_require_http_method('POST');
davez_require_public_request_context();

try {
    $logoutRate = davez_rate_limit_consume(
        'public-logout-v2',
        davez_rate_limit_request_subject(),
        30,
        60
    );
} catch (RuntimeException $exception) {
    davez_send_error(
        'security_control_unavailable',
        'Serviço temporariamente indisponível.',
        503
    );
}

if (!$logoutRate['allowed']) {
    header('Retry-After: ' . $logoutRate['retry_after']);
    davez_send_error(
        'rate_limit_exceeded',
        'Muitas tentativas. Aguarde e tente novamente.',
        429
    );
}

include __DIR__ . '/config.php';

date_default_timezone_set('America/Sao_Paulo');
$conn->query("SET time_zone = '-03:00'");
$operationalContext = new \DaVez\Domain\OperationalContext(
    new \DaVez\Domain\OperationalCycle()
);
$identity = davez_authenticated_public_identity(
    $conn,
    $operationalContext
);

try {
    if ($identity !== null) {
        davez_public_identity_store($conn)->revokeSession(
            (int) $identity['id'],
            'logout',
            $operationalContext->reference()
        );
    }

    davez_clear_public_identity_cookie();
} catch (Throwable $exception) {
    davez_send_error(
        'logout_failed',
        'Não foi possível encerrar a sessão.',
        500
    );
}

davez_send_json([
    'ok' => true,
    'identity_version' => 2,
]);
