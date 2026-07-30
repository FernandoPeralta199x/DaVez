<?php

declare(strict_types=1);

require_once __DIR__ . '/PublicIdentity.php';
require_once dirname(__DIR__) . '/Database/PublicIdentityStore.php';
require_once dirname(__DIR__) . '/Domain/OperationalContext.php';
require_once dirname(__DIR__) . '/Http/JsonResponse.php';

use DaVez\Database\PublicIdentityStore;
use DaVez\Domain\OperationalContext;

/**
 * Resolve a identidade exclusivamente pelo cookie opaco e pelo ciclo atual.
 *
 * @return array{
 *   id: int,
 *   checkin_id: int,
 *   nome: string,
 *   operational_date: string,
 *   created_at: string,
 *   expires_at: string
 * }|null
 */
function davez_authenticated_public_identity(
    mysqli $connection,
    OperationalContext $context
): ?array {
    $token = davez_public_identity_cookie_token();

    if ($token === null) {
        return null;
    }

    return (new PublicIdentityStore($connection))->findValidSession(
        davez_public_session_hash($token),
        $context->date(),
        $context->reference()
    );
}

/**
 * @return array{
 *   id: int,
 *   checkin_id: int,
 *   nome: string,
 *   operational_date: string,
 *   created_at: string,
 *   expires_at: string
 * }
 */
function davez_require_public_identity(
    mysqli $connection,
    OperationalContext $context
): array {
    $identity = davez_authenticated_public_identity(
        $connection,
        $context
    );

    if ($identity === null) {
        davez_send_error(
            'public_session_required',
            'Sua sessão expirou. Solicite um código individual ao administrador.',
            401
        );
    }

    return $identity;
}
