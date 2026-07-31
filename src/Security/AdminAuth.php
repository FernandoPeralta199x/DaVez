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
 * Monta a lista de contas: o dono (ADMIN_USER) e operadores opcionais.
 *
 * Operadores são clientes que operam o painel sem acesso aos logs. Vêm de
 * ADMIN_OPERATORS, um JSON como:
 * [{"user":"DiromaPizzaria","hash":"$2y$..."}]
 * A senha nunca aparece em texto puro; apenas o hash de password_hash().
 *
 * @param array{username?: string, password_hash?: string}|null $config
 * @return list<array{username: string, password_hash: string, role: string}>
 */
function davez_admin_accounts(?array $config = null): array
{
    $owner = davez_admin_credentials($config);
    $accounts = [[
        'username' => $owner['username'],
        'password_hash' => $owner['password_hash'],
        'role' => 'admin',
    ]];

    $raw = getenv('ADMIN_OPERATORS');

    if (!is_string($raw) || trim($raw) === '') {
        return $accounts;
    }

    $decoded = json_decode($raw, true);

    if (!is_array($decoded)) {
        throw new RuntimeException(
            'ADMIN_OPERATORS deve ser uma lista JSON de operadores.'
        );
    }

    foreach ($decoded as $entry) {
        if (!is_array($entry)) {
            throw new RuntimeException(
                'ADMIN_OPERATORS contém um operador inválido.'
            );
        }

        $username = $entry['user'] ?? $entry['username'] ?? null;
        $passwordHash = $entry['hash'] ?? $entry['password_hash'] ?? null;

        if (
            !is_string($username)
            || $username === ''
            || strlen($username) > 128
        ) {
            throw new RuntimeException(
                'ADMIN_OPERATORS contém um usuário inválido.'
            );
        }

        if (!is_string($passwordHash) || $passwordHash === '') {
            throw new RuntimeException(
                'ADMIN_OPERATORS contém um operador sem hash de senha.'
            );
        }

        if (
            (password_get_info($passwordHash)['algoName'] ?? 'unknown')
                === 'unknown'
        ) {
            throw new RuntimeException(
                'ADMIN_OPERATORS contém um hash de senha não suportado.'
            );
        }

        $accounts[] = [
            'username' => $username,
            'password_hash' => $passwordHash,
            'role' => 'operator',
        ];
    }

    return $accounts;
}

/**
 * Autentica contra dono e operadores, sem aceitar senha em texto puro.
 *
 * @param array{username?: string, password_hash?: string}|null $config
 */
function davez_admin_authenticate(
    string $providedUsername,
    string $providedPassword,
    ?array $config = null
): bool {
    $accounts = davez_admin_accounts($config);
    $matchedRole = null;
    $matchedHash = null;

    foreach ($accounts as $account) {
        if (hash_equals(
            hash('sha256', $account['username']),
            hash('sha256', $providedUsername)
        )) {
            $matchedRole = $account['role'];
            $matchedHash = $account['password_hash'];
        }
    }

    // Sempre verifica uma senha para não revelar a existência do usuário pelo
    // tempo de resposta. Sem correspondência, usa o hash do dono como isca.
    $passwordMatches = password_verify(
        $providedPassword,
        $matchedHash ?? $accounts[0]['password_hash']
    );

    if ($matchedRole === null || !$passwordMatches) {
        return false;
    }

    davez_start_secure_session();

    if (!session_regenerate_id(true)) {
        throw new RuntimeException('Não foi possível rotacionar a sessão.');
    }

    $now = time();
    $_SESSION['davez_admin_auth'] = [
        'role' => $matchedRole,
        'issued_at' => $now,
        'last_activity' => $now,
    ];
    unset($_SESSION['davez_csrf_token']);

    return true;
}

/**
 * Apenas o dono (papel admin) pode ver os logs de erro.
 */
function davez_admin_can_view_logs(): bool
{
    $identity = davez_authenticated_admin_identity();

    return is_array($identity) && ($identity['role'] ?? null) === 'admin';
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
