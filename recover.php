<?php

declare(strict_types=1);

require_once __DIR__ . '/src/Security/Bootstrap.php';
require_once __DIR__ . '/src/Domain/OperationalCycle.php';
require_once __DIR__ . '/src/Domain/OperationalContext.php';
require_once __DIR__ . '/src/Database/bootstrap.php';
require_once __DIR__ . '/src/Http/PublicIdentityView.php';

davez_install_safe_exception_handler();
davez_require_http_method('POST');
davez_require_public_request_context();

try {
    davez_assert_allowed_input_keys($_POST, ['access_code']);
    $recoveryRate = davez_rate_limit_consume(
        'public-recovery-v2',
        davez_rate_limit_request_subject(),
        5,
        300
    );
} catch (InvalidArgumentException $exception) {
    davez_send_error(
        'invalid_request',
        'Código de recuperação inválido.',
        400
    );
} catch (RuntimeException $exception) {
    davez_send_error(
        'security_control_unavailable',
        'Serviço temporariamente indisponível.',
        503
    );
}

if (!$recoveryRate['allowed']) {
    header('Retry-After: ' . $recoveryRate['retry_after']);
    davez_send_error(
        'rate_limit_exceeded',
        'Muitas tentativas. Aguarde e tente novamente.',
        429
    );
}

include __DIR__ . '/config.php';
include __DIR__ . '/log.php';

$operationalCycle = \DaVez\Domain\OperationalCycle::fromEnvironment();
date_default_timezone_set($operationalCycle->timezone()->getName());
davez_configure_operational_database_timezone($conn, $operationalCycle);
$operationalContext = new \DaVez\Domain\OperationalContext(
    $operationalCycle
);
$operationalDate = $operationalContext->date();
$now = $operationalContext->reference();

try {
    $accessCode = davez_input_string(
        $_POST,
        'access_code',
        8,
        9
    );
    $codeHash = davez_public_ticket_hash($accessCode);
    $sessionToken = davez_public_session_token();
    $sessionHash = davez_public_session_hash($sessionToken);
    $sessionExpiresAt = davez_public_session_expires_at(
        $operationalContext,
        $now
    );
    davez_public_identity_cookie_name();
} catch (InvalidArgumentException $exception) {
    davez_send_error(
        'invalid_access_code',
        'Código de recuperação inválido.',
        400
    );
} catch (RuntimeException $exception) {
    davez_send_error(
        'identity_service_unavailable',
        'Identidade pública temporariamente indisponível.',
        503
    );
}

$store = davez_public_identity_store($conn);
$lockedTransactions = davez_locked_transaction_runner($conn);
$recoveredIdentity = null;

try {
    $lockedTransactions->run(
        'public-recovery:' . $operationalDate,
        static function () use (
            $conn,
            $store,
            $codeHash,
            $operationalDate,
            $now,
            $sessionHash,
            $sessionExpiresAt,
            &$recoveredIdentity
        ): void {
            $code = $store->loadDailyCodeForUpdate(
                $codeHash,
                $operationalDate,
                $now
            );

            if ($code === null) {
                throw new DomainException('invalid_recovery_code');
            }

            if ($code['checkin_id'] === null) {
                // Código válido, mas ainda sem check-in: recuperar não se aplica.
                throw new DomainException('not_checked_in');
            }

            $checkinId = (int) $code['checkin_id'];
            $checkin = $conn->prepare(
                'SELECT nome, COALESCE(is_closed, 0)
                 FROM checkins
                 WHERE id=?
                   AND operational_date=?
                 LIMIT 1
                 FOR UPDATE'
            );

            if (!$checkin) {
                throw new RuntimeException(
                    'Check-in indisponível para recuperação.'
                );
            }

            $checkin->bind_param(
                'is',
                $checkinId,
                $operationalDate
            );

            if (!$checkin->execute()) {
                $checkin->close();
                throw new RuntimeException(
                    'Check-in indisponível para recuperação.'
                );
            }

            $name = null;
            $isClosed = null;
            $checkin->bind_result($name, $isClosed);

            if (!$checkin->fetch()) {
                $checkin->close();
                throw new DomainException('invalid_recovery_code');
            }
            $checkin->close();

            if ((int) $isClosed === 1) {
                throw new DomainException('checkin_closed');
            }

            $store->revokeActiveSessions(
                $checkinId,
                'recovery',
                $now
            );

            $store->touchDailyCode((int) $code['id'], $now);

            $store->createSession(
                $checkinId,
                $sessionHash,
                $operationalDate,
                $now,
                $sessionExpiresAt
            );

            $recoveredIdentity = [
                'checkin_id' => $checkinId,
                'nome' => (string) $name,
            ];
        }
    );
} catch (DomainException $exception) {
    if ($exception->getMessage() === 'not_checked_in') {
        davez_send_error(
            'not_checked_in',
            'Esse código ainda não fez check-in hoje. Faça o check-in com seu nome.',
            409
        );
    }

    if ($exception->getMessage() === 'checkin_closed') {
        davez_send_error(
            'checkin_closed',
            'Seu check-in de hoje foi encerrado. Solicite um novo código à equipe.',
            409
        );
    }

    davez_send_error(
        'invalid_recovery_code',
        'Código inválido ou expirado.',
        403
    );
} catch (\DaVez\Database\LockUnavailable $exception) {
    header('Retry-After: 2');
    davez_send_error(
        'recovery_busy',
        'Recuperação ocupada. Aguarde e tente novamente.',
        503
    );
} catch (Throwable $exception) {
    log_event('PUBLIC_IDENTITY_RECOVERY_FAILED');
    davez_send_error(
        'recovery_failed',
        'Não foi possível recuperar a sessão.',
        500
    );
}

davez_set_public_identity_cookie(
    $sessionToken,
    $sessionExpiresAt
);

davez_send_json([
    'ok' => true,
    'identity_version' => 2,
    'operational_date' => $operationalDate,
    'me' => davez_public_identity_me(
        $conn,
        $recoveredIdentity,
        $operationalContext
    ),
]);
