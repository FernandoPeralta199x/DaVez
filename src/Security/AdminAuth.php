<?php

declare(strict_types=1);

require_once __DIR__ . '/Session.php';
require_once dirname(__DIR__) . '/Http/JsonResponse.php';

/**
 * Carrega apenas usuário e hash de senha.
 *
 * @param array{username?: string, password_hash?: string}|null $config
 * @return array{username: string, password_hash: string}
 */
function davez_admin_credentials(?array $config = null): array
{
    $username = $config['username'] ?? getenv('ADMIN_USER');
    $passwordHash = $config['password_hash']
        ?? getenv('ADMIN_PASSWORD_HASH');

    if (
        !is_string($username)
        || $username === ''
        || strlen($username) > 128
    ) {
        throw new RuntimeException(
            'ADMIN_USER não está configurado corretamente.'
        );
    }

    if (!is_string($passwordHash) || $passwordHash === '') {
        throw new RuntimeException(
            'ADMIN_PASSWORD_HASH não está configurado.'
        );
    }

    $passwordInfo = password_get_info($passwordHash);

    if (($passwordInfo['algoName'] ?? 'unknown') === 'unknown') {
        throw new RuntimeException(
            'ADMIN_PASSWORD_HASH não contém um hash suportado.'
        );
    }

    return [
        'username' => $username,
        'password_hash' => $passwordHash,
    ];
}

/**
 * Autentica sem aceitar senha administrativa em texto puro na configuração.
 *
 * @param array{username?: string, password_hash?: string}|null $config
 */
function davez_admin_authenticate(
    string $providedUsername,
    string $providedPassword,
    ?array $config = null
): bool {
    $credentials = davez_admin_credentials($config);
    $usernameMatches = hash_equals(
        hash('sha256', $credentials['username']),
        hash('sha256', $providedUsername)
    );
    $passwordMatches = password_verify(
        $providedPassword,
        $credentials['password_hash']
    );

    if (!$usernameMatches || !$passwordMatches) {
        return false;
    }

    davez_start_secure_session();

    if (!session_regenerate_id(true)) {
        throw new RuntimeException('Não foi possível rotacionar a sessão.');
    }

    $now = time();
    $_SESSION['davez_admin_auth'] = [
        'role' => 'admin',
        'issued_at' => $now,
        'last_activity' => $now,
    ];
    unset($_SESSION['davez_csrf_token']);

    return true;
}

function davez_require_admin(): void
{
    if (!davez_admin_session_is_authenticated()) {
        davez_send_error(
            'authentication_required',
            'Autenticação administrativa necessária.',
            401
        );
    }
}

function davez_admin_logout(): void
{
    davez_destroy_secure_session();
}
