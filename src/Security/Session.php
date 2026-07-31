<?php

declare(strict_types=1);

/**
 * Papéis administrativos válidos.
 *
 * - admin: dono, com acesso total, inclusive aos logs;
 * - operator: cliente, opera o painel sem acesso aos logs.
 */
const DAVEZ_ADMIN_ROLES = ['admin', 'operator'];

/**
 * Proxies autorizados a informar o protocolo original da requisição.
 *
 * Sem APP_TRUSTED_PROXIES nenhum cabeçalho encaminhado é considerado. A
 * configuração aceita IPs e faixas CIDR separados por vírgula; um valor
 * malformado interrompe a requisição em vez de degradar para HTTP silencioso.
 *
 * @return list<string>
 */
function davez_trusted_proxies(): array
{
    $rawValue = getenv('APP_TRUSTED_PROXIES');

    if (!is_string($rawValue) || trim($rawValue) === '') {
        return [];
    }

    $proxies = [];

    foreach (explode(',', $rawValue) as $entry) {
        $candidate = trim($entry);

        if ($candidate === '') {
            continue;
        }

        if (!davez_is_ip_or_cidr($candidate)) {
            throw new RuntimeException(
                'APP_TRUSTED_PROXIES contém um endereço ou faixa inválida.'
            );
        }

        $proxies[] = $candidate;
    }

    return $proxies;
}

function davez_is_ip_or_cidr(string $candidate): bool
{
    if (!str_contains($candidate, '/')) {
        return filter_var($candidate, FILTER_VALIDATE_IP) !== false;
    }

    [$subnet, $prefix] = explode('/', $candidate, 2);
    $packedSubnet = @inet_pton($subnet);

    if ($packedSubnet === false) {
        return false;
    }

    if (filter_var($prefix, FILTER_VALIDATE_INT) === false) {
        return false;
    }

    return (int) $prefix >= 0
        && (int) $prefix <= strlen($packedSubnet) * 8;
}

function davez_ip_matches(string $address, string $range): bool
{
    $packedAddress = @inet_pton($address);

    if ($packedAddress === false) {
        return false;
    }

    if (!str_contains($range, '/')) {
        $packedRange = @inet_pton($range);

        return $packedRange !== false
            && hash_equals($packedRange, $packedAddress);
    }

    [$subnet, $prefix] = explode('/', $range, 2);
    $packedSubnet = @inet_pton($subnet);
    $prefixBits = (int) $prefix;

    // Endereços de famílias diferentes nunca pertencem à mesma faixa.
    if (
        $packedSubnet === false
        || strlen($packedSubnet) !== strlen($packedAddress)
    ) {
        return false;
    }

    $fullBytes = intdiv($prefixBits, 8);
    $remainingBits = $prefixBits % 8;

    if (
        $fullBytes > 0
        && !hash_equals(
            substr($packedSubnet, 0, $fullBytes),
            substr($packedAddress, 0, $fullBytes)
        )
    ) {
        return false;
    }

    if ($remainingBits === 0) {
        return true;
    }

    $mask = ~((1 << (8 - $remainingBits)) - 1) & 0xFF;

    return (ord($packedSubnet[$fullBytes]) & $mask)
        === (ord($packedAddress[$fullBytes]) & $mask);
}

function davez_request_is_from_trusted_proxy(): bool
{
    $remoteAddress = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));

    if ($remoteAddress === '') {
        return false;
    }

    foreach (davez_trusted_proxies() as $proxy) {
        if (davez_ip_matches($remoteAddress, $proxy)) {
            return true;
        }
    }

    return false;
}

/**
 * O protocolo encaminhado só é aceito quando a conexão vem de um proxy
 * declarado. Caso contrário qualquer cliente poderia forjar o cabeçalho e
 * fazer o aplicativo emitir cookies Secure em transporte inseguro.
 */
function davez_forwarded_protocol(): ?string
{
    if (!davez_request_is_from_trusted_proxy()) {
        return null;
    }

    $forwarded = (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');

    if ($forwarded === '') {
        return null;
    }

    // A cadeia de proxies acrescenta valores à direita; o primeiro é o cliente.
    $protocol = strtolower(trim(explode(',', $forwarded)[0]));

    return in_array($protocol, ['http', 'https'], true)
        ? $protocol
        : null;
}

function davez_is_https_request(): bool
{
    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));

    if (
        $https === 'on'
        || $https === '1'
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443'
    ) {
        return true;
    }

    return davez_forwarded_protocol() === 'https';
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

    return in_array($auth['role'] ?? null, DAVEZ_ADMIN_ROLES, true);
}

/**
 * A identidade administrativa é derivada exclusivamente da sessão.
 *
 * @return array{role: 'admin'|'operator'}|null
 */
function davez_authenticated_admin_identity(): ?array
{
    if (!davez_admin_session_is_authenticated()) {
        return null;
    }

    $role = $_SESSION['davez_admin_auth']['role'] ?? null;

    return in_array($role, DAVEZ_ADMIN_ROLES, true)
        ? ['role' => $role]
        : null;
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
