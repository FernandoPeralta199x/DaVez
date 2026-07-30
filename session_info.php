<?php

declare(strict_types=1);

require_once __DIR__ . '/src/Security/Bootstrap.php';
require_once __DIR__ . '/src/Security/PublicIdentityAuth.php';
require_once __DIR__ . '/src/Domain/OperationalCycle.php';
require_once __DIR__ . '/src/Domain/OperationalContext.php';
require_once __DIR__ . '/src/Http/PublicIdentityView.php';

davez_install_safe_exception_handler();
davez_require_http_method('GET');
davez_bootstrap_public_request_context();

include __DIR__ . '/config.php';

date_default_timezone_set('America/Sao_Paulo');
$conn->query("SET time_zone = '-03:00'");
$operationalContext = new \DaVez\Domain\OperationalContext(
    new \DaVez\Domain\OperationalCycle()
);

try {
    $identity = davez_authenticated_public_identity(
        $conn,
        $operationalContext
    );
} catch (RuntimeException $exception) {
    davez_send_error(
        'https_required',
        'A identidade pública exige uma conexão HTTPS.',
        426
    );
}

$payload = [
    'ok' => true,
    'identity_version' => 2,
    'authenticated' => $identity !== null,
    'operational_date' => $operationalContext->date(),
    'me' => null,
];

if ($identity !== null) {
    try {
        $payload['me'] = davez_public_identity_me(
            $conn,
            $identity,
            $operationalContext
        );
    } catch (Throwable $exception) {
        davez_send_error(
            'identity_state_unavailable',
            'Estado da sessão temporariamente indisponível.',
            500
        );
    }
}

davez_send_json($payload);
