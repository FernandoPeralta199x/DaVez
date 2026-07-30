<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Security/Bootstrap.php';
require_once __DIR__ . '/../src/Security/PublicIdentityAuth.php';
require_once __DIR__ . '/../src/Domain/OperationalCycle.php';
require_once __DIR__ . '/../src/Domain/OperationalContext.php';
require_once __DIR__ . '/../src/Domain/Geofence.php';
require_once __DIR__ . '/../src/Database/bootstrap.php';
require_once __DIR__ . '/../src/Http/PublicIdentityView.php';

davez_install_safe_exception_handler();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: ../index.html?warm=1', true, 303);
    exit;
}

davez_require_http_method('POST');
davez_require_public_request_context();

if (
    isset($_POST['nome'])
    || isset($_POST['token'])
    || isset($_POST['client_id'])
) {
    davez_send_error(
        'identity_upgrade_required',
        'Atualize o aplicativo para utilizar sua sessão segura.',
        426
    );
}

try {
    davez_assert_allowed_input_keys($_POST, ['lat', 'lng']);
    $enterRate = davez_rate_limit_consume(
        'public-enter-queue-v2',
        davez_rate_limit_request_subject(),
        12,
        60
    );
} catch (InvalidArgumentException $exception) {
    davez_send_error(
        'invalid_request',
        'Dados de entrada inválidos.',
        400
    );
} catch (RuntimeException $exception) {
    davez_send_error(
        'security_control_unavailable',
        'Serviço temporariamente indisponível.',
        503
    );
}

if (!$enterRate['allowed']) {
    header('Retry-After: ' . $enterRate['retry_after']);
    davez_send_error(
        'rate_limit_exceeded',
        'Muitas tentativas. Aguarde e tente novamente.',
        429
    );
}

include __DIR__ . '/../config.php';
include __DIR__ . '/../log.php';

date_default_timezone_set('America/Sao_Paulo');
$conn->query("SET time_zone = '-03:00'");
$operationalContext = new \DaVez\Domain\OperationalContext(
    new \DaVez\Domain\OperationalCycle()
);
$operationalDate = $operationalContext->date();
$identity = davez_require_public_identity(
    $conn,
    $operationalContext
);

try {
    $latitude = davez_input_float($_POST, 'lat', -90, 90);
    $longitude = davez_input_float($_POST, 'lng', -180, 180);
} catch (InvalidArgumentException $exception) {
    davez_send_error(
        'invalid_location',
        'Localização inválida.',
        400
    );
}

$settingsResult = $conn->query(
    'SELECT lat_base, lng_base, raio
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

$checkinId = (int) $identity['checkin_id'];
$name = (string) $identity['nome'];
$allocator = davez_atomic_order_allocator($conn);

try {
    $allocator->allocateAndPersist(
        'fila_da_vez:' . $operationalDate,
        static function () use (
            $conn,
            $operationalDate,
            $checkinId
        ): int {
            $existing = $conn->prepare(
                "SELECT status
                 FROM fila_da_vez
                 WHERE dia=?
                   AND checkin_id=?
                 LIMIT 1
                 FOR UPDATE"
            );

            if (!$existing) {
                throw new RuntimeException(
                    'Fila indisponível para validação.'
                );
            }

            $existing->bind_param(
                'si',
                $operationalDate,
                $checkinId
            );

            if (!$existing->execute()) {
                $existing->close();
                throw new RuntimeException(
                    'Fila indisponível para validação.'
                );
            }

            $status = null;
            $existing->bind_result($status);
            $hasExisting = $existing->fetch();
            $existing->close();

            if ($hasExisting && (string) $status === 'na_fila') {
                throw new DomainException('already_waiting');
            }

            $maximum = $conn->prepare(
                'SELECT COALESCE(MAX(ordem), 0)
                 FROM fila_da_vez
                 WHERE dia=?'
            );

            if (!$maximum) {
                throw new RuntimeException(
                    'Fila indisponível para ordenação.'
                );
            }

            $maximum->bind_param('s', $operationalDate);

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
            $operationalDate,
            $checkinId,
            $name
        ): void {
            $insert = $conn->prepare(
                "INSERT INTO fila_da_vez
                   (dia, checkin_id, nome, entered_at, ordem,
                    status, last_action_at)
                 VALUES (?, ?, ?, NOW(), ?, 'na_fila', NOW())
                 ON DUPLICATE KEY UPDATE
                   nome=VALUES(nome),
                   entered_at=NOW(),
                   ordem=VALUES(ordem),
                   status='na_fila',
                   last_action_at=NOW()"
            );

            if (!$insert) {
                throw new RuntimeException(
                    'Fila indisponível para inserção.'
                );
            }

            $insert->bind_param(
                'sisi',
                $operationalDate,
                $checkinId,
                $name,
                $order
            );

            if (!$insert->execute() || $insert->affected_rows <= 0) {
                $insert->close();
                throw new RuntimeException(
                    'Fila indisponível para inserção.'
                );
            }
            $insert->close();
        }
    );
} catch (DomainException $exception) {
    davez_send_error(
        'already_waiting',
        'Você já está aguardando na fila DaVez.',
        409
    );
} catch (\DaVez\Database\LockUnavailable $exception) {
    header('Retry-After: 2');
    davez_send_error(
        'queue_busy',
        'Fila ocupada. Aguarde e tente novamente.',
        503
    );
} catch (Throwable $exception) {
    log_event('PUBLIC_QUEUE_ENTRY_FAILED');
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
    'me' => davez_public_identity_me(
        $conn,
        $identity,
        $operationalContext
    ),
]);
