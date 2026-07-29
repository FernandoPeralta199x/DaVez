<?php

declare(strict_types=1);

function davez_is_https_request(): bool
{
    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));

    return $https === 'on'
        || $https === '1'
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
}

function davez_positive_environment_integer(
    string $name,
    int $default,
    int $minimum,
    int $maximum
): int {
    $rawValue = getenv($name);

    if ($rawValue === false || $rawValue === '') {
        return $default;
    }

    if (
        filter_var($rawValue, FILTER_VALIDATE_INT) === false
        || (int) $rawValue < $minimum
        || (int) $rawValue > $maximum
    ) {
        throw new RuntimeException(sprintf(
            'Configuração numérica inválida: %s.',
            $name
        ));
    }

    return (int) $rawValue;
}

/**
 * Inicia uma sessão com cookies protegidos e sem IDs na URL.
 *
 * @param array{
 *   name?: string,
 *   secure?: bool,
 *   same_site?: 'Lax'|'Strict',
 *   path?: string
 * } $options
 */
function davez_start_secure_session(array $options = []): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    if (headers_sent($sourceFile, $sourceLine)) {
        throw new RuntimeException(sprintf(
            'A sessão deve iniciar antes da saída HTTP (%s:%d).',
            $sourceFile,
            $sourceLine
        ));
    }

    $sessionName = $options['name']
        ?? (getenv('APP_SESSION_NAME') ?: 'davez_session');

    if (preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $sessionName) !== 1) {
        throw new RuntimeException('Nome de sessão inválido.');
    }

    $sameSite = $options['same_site'] ?? 'Strict';

    if (!in_array($sameSite, ['Lax', 'Strict'], true)) {
        throw new InvalidArgumentException('Política SameSite inválida.');
    }

    $cookiePath = $options['path'] ?? '/';

    if ($cookiePath === '' || $cookiePath[0] !== '/') {
        throw new InvalidArgumentException('Caminho do cookie inválido.');
    }

    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');

    session_name($sessionName);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $cookiePath,
        'domain' => '',
        'secure' => $options['secure'] ?? davez_is_https_request(),
        'httponly' => true,
        'samesite' => $sameSite,
    ]);

    if (!session_start()) {
        throw new RuntimeException('Não foi possível iniciar a sessão.');
    }
}

function davez_clear_admin_session_state(): void
{
    unset(
        $_SESSION['davez_admin_auth'],
        $_SESSION['davez_csrf_token']
    );
}

function davez_admin_session_is_authenticated(): bool
{
    davez_start_secure_session();

    $auth = $_SESSION['davez_admin_auth'] ?? null;

    if (!is_array($auth)) {
        return false;
    }

    $now = time();
    $issuedAt = filter_var(
        $auth['issued_at'] ?? null,
        FILTER_VALIDATE_INT
    );
    $lastActivity = filter_var(
        $auth['last_activity'] ?? null,
        FILTER_VALIDATE_INT
    );

    if ($issuedAt === false || $lastActivity === false) {
        davez_clear_admin_session_state();
        return false;
    }

    $idleTimeout = davez_positive_environment_integer(
        'ADMIN_SESSION_IDLE_SECONDS',
        1800,
        60,
        86400
    );
    $absoluteTimeout = davez_positive_environment_integer(
        'ADMIN_SESSION_ABSOLUTE_SECONDS',
        28800,
        300,
        604800
    );

    if (
        $now - $lastActivity > $idleTimeout
        || $now - $issuedAt > $absoluteTimeout
    ) {
        davez_clear_admin_session_state();
        return false;
    }

    $_SESSION['davez_admin_auth']['last_activity'] = $now;

    return ($auth['role'] ?? null) === 'admin';
}

/**
 * A identidade administrativa é derivada exclusivamente da sessão.
 *
 * @return array{role: 'admin'}|null
 */
function davez_authenticated_admin_identity(): ?array
{
    if (!davez_admin_session_is_authenticated()) {
        return null;
    }

    return ['role' => 'admin'];
}

function davez_destroy_secure_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        davez_start_secure_session();
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Strict',
        ]);
    }

    session_destroy();
}
