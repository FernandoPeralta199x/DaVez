<?php

declare(strict_types=1);

require_once __DIR__ . '/src/Security/Bootstrap.php';
require_once __DIR__ . '/src/Domain/OperationalCycle.php';
require_once __DIR__ . '/src/Domain/OperationalContext.php';
require_once __DIR__ . '/src/Domain/Geofence.php';
require_once __DIR__ . '/src/Database/bootstrap.php';
require_once __DIR__ . '/src/Http/PublicIdentityView.php';

davez_install_safe_exception_handler();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: index.html?warm=1', true, 303);
    exit;
}

davez_require_http_method('POST');
davez_require_public_request_context();

if (isset($_POST['token']) || isset($_POST['client_id'])) {
    davez_send_error(
        'identity_upgrade_required',
        'Atualize o aplicativo para utilizar um código individual.',
        426
    );
}

try {
    davez_assert_allowed_input_keys(
        $_POST,
        ['nome', 'access_code', 'lat', 'lng']
    );
    $checkinRate = davez_rate_limit_consume(
        'public-checkin-v2',
        davez_rate_limit_request_subject(),
        5,
        60
    );
} catch (InvalidArgumentException $exception) {
    davez_send_error(
        'invalid_request',
        'Dados de check-in inválidos.',
        400
    );
} catch (RuntimeException $exception) {
    davez_send_error(
        'security_control_unavailable',
        'Serviço temporariamente indisponível.',
        503
    );
}

if (!$checkinRate['allowed']) {
    header('Retry-After: ' . $checkinRate['retry_after']);
    davez_send_error(
        'rate_limit_exceeded',
        'Muitas tentativas. Aguarde e tente novamente.',
        429
    );
}

include __DIR__ . '/config.php';
include __DIR__ . '/log.php';

date_default_timezone_set('America/Sao_Paulo');
$conn->query("SET time_zone = '-03:00'");
$operationalContext = new \DaVez\Domain\OperationalContext(
    new \DaVez\Domain\OperationalCycle()
);
$operationalStart = $operationalContext->startSql();
$operationalEnd = $operationalContext->endSql();
$operationalDate = $operationalContext->date();
$now = $operationalContext->reference();

try {
    $name = davez_input_string($_POST, 'nome', 2, 80);
    $accessCode = davez_input_string(
        $_POST,
        'access_code',
        8,
        9
    );
    $latitude = davez_input_float($_POST, 'lat', -90, 90);
    $longitude = davez_input_float($_POST, 'lng', -180, 180);
    $ticketHash = davez_public_ticket_hash($accessCode);
    $sessionToken = davez_public_session_token();
    $sessionHash = davez_public_session_hash($sessionToken);
    $sessionExpiresAt = davez_public_session_expires_at(
        $operationalContext,
        $now
    );
    davez_public_identity_cookie_name();
} catch (InvalidArgumentException $exception) {
    davez_send_error(
        'invalid_checkin_data',
        'Confira nome, código individual e localização.',
        400
    );
} catch (RuntimeException $exception) {
    davez_send_error(
        'identity_service_unavailable',
        'Identidade pública temporariamente indisponível.',
        503
    );
}

$settingsResult = $conn->query(
    'SELECT chamada_aberta, lat_base, lng_base, raio
     FROM settings
     WHERE id=1
     LIMIT 1'
);
$settings = $settingsResult ? $settingsResult->fetch_assoc() : null;

if (!$settings) {
    davez_send_error(
        'settings_unavailable',
        'Configurações temporariamente indisponíveis.',
        500
    );
}

if ((int) ($settings['chamada_aberta'] ?? 0) !== 1) {
    davez_send_error(
        'queue_closed',
        'A chamada está fechada.',
        403
    );
}

try {
    $geofence = \DaVez\Domain\Geofence::evaluate(
        (float) $settings['lat_base'],
        (float) $settings['lng_base'],
        (float) $settings['raio'],
        $latitude,
        $longitude
    );
} catch (InvalidArgumentException $exception) {
    davez_send_error(
        'geofence_unavailable',
        'Validação de localização temporariamente indisponível.',
        503
    );
}

if (!$geofence['within']) {
    davez_send_error(
        'outside_allowed_area',
        'Você não está no local autorizado.',
        403
    );
}

$store = davez_public_identity_store($conn);
$allocator = davez_atomic_order_allocator($conn);
$loadedTicket = null;
$checkinId = null;

try {
    $position = $allocator->allocateAndPersist(
        'checkins:' . $operationalDate,
        static function () use (
            $conn,
            $store,
            $ticketHash,
            $operationalDate,
            $now,
            $name,
            $operationalStart,
            $operationalEnd,
            &$loadedTicket
        ): int {
            $loadedTicket = $store->loadTicketForUpdate(
                $ticketHash,
                'checkin',
                $operationalDate,
                $now
            );

            if ($loadedTicket === null) {
                throw new DomainException('invalid_access_code');
            }

            $duplicate = $conn->prepare(
                'SELECT id
                 FROM checkins
                 WHERE LOWER(TRIM(nome))=LOWER(TRIM(?))
                   AND data_hora >= ?
                   AND data_hora < ?
                 LIMIT 1
                 FOR UPDATE'
            );

            if (!$duplicate) {
                throw new RuntimeException(
                    'Check-in indisponível para validação.'
                );
            }

            $duplicate->bind_param(
                'sss',
                $name,
                $operationalStart,
                $operationalEnd
            );

            if (!$duplicate->execute()) {
                $duplicate->close();
                throw new RuntimeException(
                    'Check-in indisponível para validação.'
                );
            }

            $duplicate->store_result();
            $alreadyExists = $duplicate->num_rows > 0;
            $duplicate->close();

            if ($alreadyExists) {
                throw new DomainException('duplicate_name');
            }

            $maximum = $conn->prepare(
                'SELECT COALESCE(MAX(ordem), 0)
                 FROM checkins
                 WHERE data_hora >= ?
                   AND data_hora < ?'
            );

            if (!$maximum) {
                throw new RuntimeException(
                    'Fila indisponível para ordenação.'
                );
            }

            $maximum->bind_param(
                'ss',
                $operationalStart,
                $operationalEnd
            );

            if (!$maximum->execute()) {
                $maximum->close();
                throw new RuntimeException(
                    'Fila indisponível para ordenação.'
                );
            }

            $maximumOrder = 0;
            $maximum->bind_result($maximumOrder);
            $maximum->fetch();
            $maximum->close();

            return (int) $maximumOrder;
        },
        static function (int $order) use (
            $conn,
            $store,
            $name,
            $operationalDate,
            $now,
            $sessionHash,
            $sessionExpiresAt,
            &$loadedTicket,
            &$checkinId
        ): void {
            $insert = $conn->prepare(
                'INSERT INTO checkins
                   (nome, data_hora, operational_date, ordem)
                 VALUES (?, NOW(), ?, ?)'
            );

            if (!$insert) {
                throw new RuntimeException(
                    'Check-in indisponível para inserção.'
                );
            }

            $insert->bind_param(
                'ssi',
                $name,
                $operationalDate,
                $order
            );

            if (!$insert->execute() || $insert->affected_rows !== 1) {
                $insert->close();
                throw new RuntimeException(
                    'Check-in indisponível para inserção.'
                );
            }

            $checkinId = (int) $conn->insert_id;
            $insert->close();

            if (
                !is_array($loadedTicket)
                || !$store->consumeTicket(
                    (int) $loadedTicket['id'],
                    $checkinId,
                    $now
                )
            ) {
                throw new RuntimeException(
                    'O código individual não pôde ser consumido.'
                );
            }

            $store->createSession(
                $checkinId,
                $sessionHash,
                $operationalDate,
                $now,
                $sessionExpiresAt
            );
        }
    );
} catch (DomainException $exception) {
    if ($exception->getMessage() === 'duplicate_name') {
        davez_send_error(
            'checkin_already_exists',
            'Este check-in já existe. Solicite um código de recuperação ao administrador.',
            409
        );
    }

    davez_send_error(
        'invalid_access_code',
        'Código individual inválido, expirado ou já utilizado.',
        403
    );
} catch (\DaVez\Database\LockUnavailable $exception) {
    header('Retry-After: 2');
    davez_send_error(
        'queue_busy',
        'Fila ocupada. Aguarde e tente novamente.',
        503
    );
} catch (Throwable $exception) {
    log_event('PUBLIC_IDENTITY_CHECKIN_FAILED');
    davez_send_error(
        'checkin_failed',
        'Não foi possível registrar o check-in.',
        500
    );
}

davez_set_public_identity_cookie(
    $sessionToken,
    $sessionExpiresAt
);

$identity = [
    'checkin_id' => (int) $checkinId,
    'nome' => $name,
];

davez_send_json([
    'ok' => true,
    'identity_version' => 2,
    'operational_date' => $operationalDate,
    'me' => davez_public_identity_me(
        $conn,
        $identity,
        $operationalContext
    ),
]);
