<?php

declare(strict_types=1);

require_once __DIR__ . '/Session.php';
require_once dirname(__DIR__) . '/Domain/OperationalCycle.php';
require_once dirname(__DIR__) . '/Domain/OperationalContext.php';

use DaVez\Domain\OperationalContext;

const DAVEZ_PUBLIC_TICKET_ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
const DAVEZ_PUBLIC_TICKET_LENGTH = 16;
const DAVEZ_PUBLIC_TICKET_TTL_SECONDS = 600;
const DAVEZ_PUBLIC_SESSION_BYTES = 32;
const DAVEZ_PUBLIC_SESSION_MAX_SECONDS = 86400;
const DAVEZ_PUBLIC_IDENTITY_COOKIE_HTTPS = '__Host-davez_public';
const DAVEZ_PUBLIC_IDENTITY_COOKIE_LOCAL = 'davez_public_dev';

/**
 * Gera 16 caracteres Crockford Base32 e os apresenta em quatro grupos.
 */
function davez_public_ticket_code(): string
{
    $characters = '';

    for ($index = 0; $index < DAVEZ_PUBLIC_TICKET_LENGTH; $index++) {
        $randomByte = ord(random_bytes(1));
        $characters .= DAVEZ_PUBLIC_TICKET_ALPHABET[$randomByte & 31];
    }

    return implode('-', str_split($characters, 4));
}

/**
 * Normaliza separadores e os aliases Crockford O/I/L.
 *
 * @throws InvalidArgumentException quando o código não possui exatamente
 *         16 caracteres Crockford Base32.
 */
function davez_normalize_public_ticket_code(string $code): string
{
    $compact = preg_replace('/[\s-]+/u', '', $code);

    if ($compact === null) {
        throw new InvalidArgumentException('Código de acesso inválido.');
    }

    $normalized = strtr(strtoupper($compact), [
        'O' => '0',
        'I' => '1',
        'L' => '1',
    ]);

    if (
        strlen($normalized) !== DAVEZ_PUBLIC_TICKET_LENGTH
        || preg_match('/\A[0-9A-HJKMNP-TV-Z]{16}\z/', $normalized) !== 1
    ) {
        throw new InvalidArgumentException('Código de acesso inválido.');
    }

    return $normalized;
}

/**
 * Retorna o HMAC-SHA256 binário persistível em BINARY(32).
 */
function davez_public_ticket_hash(string $code): string
{
    $key = getenv('PUBLIC_TICKET_HMAC_KEY');

    if (
        $key === false
        || strlen($key) < 32
    ) {
        throw new RuntimeException(
            'PUBLIC_TICKET_HMAC_KEY deve possuir ao menos 32 bytes.'
        );
    }

    return hash_hmac(
        'sha256',
        davez_normalize_public_ticket_code($code),
        $key,
        true
    );
}

/**
 * Gera 32 bytes aleatórios transportados em Base64 URL-safe sem padding.
 */
function davez_public_session_token(): string
{
    return rtrim(
        strtr(base64_encode(random_bytes(DAVEZ_PUBLIC_SESSION_BYTES)), '+/', '-_'),
        '='
    );
}

/**
 * Retorna o SHA-256 binário dos 32 bytes originais do token.
 */
function davez_public_session_hash(string $token): string
{
    $rawToken = davez_decode_public_session_token($token);

    return hash('sha256', $rawToken, true);
}

/**
 * @throws InvalidArgumentException quando o token não representa 32 bytes.
 */
function davez_decode_public_session_token(string $token): string
{
    if (preg_match('/\A[A-Za-z0-9_-]{43}\z/', $token) !== 1) {
        throw new InvalidArgumentException('Token de sessão pública inválido.');
    }

    $rawToken = base64_decode(
        strtr($token, '-_', '+/') . '=',
        true
    );

    if (
        $rawToken === false
        || strlen($rawToken) !== DAVEZ_PUBLIC_SESSION_BYTES
    ) {
        throw new InvalidArgumentException('Token de sessão pública inválido.');
    }

    return $rawToken;
}

/**
 * Limita a sessão ao fim do ciclo operacional e a 24 horas exatas.
 */
function davez_public_session_expires_at(
    OperationalContext $context,
    ?DateTimeInterface $issuedAt = null
): DateTimeImmutable {
    $issued = $issuedAt === null
        ? $context->reference()
        : DateTimeImmutable::createFromInterface($issuedAt);
    $cycleEnd = $context->end();

    if ($issued < $context->start() || $issued >= $cycleEnd) {
        throw new InvalidArgumentException(
            'A sessão deve ser criada dentro do ciclo operacional.'
        );
    }

    $maximum = $issued->setTimestamp(
        $issued->getTimestamp() + DAVEZ_PUBLIC_SESSION_MAX_SECONDS
    );

    return $maximum < $cycleEnd ? $maximum : $cycleEnd;
}

/**
 * Retorna dez minutos exatos ou recusa a emissão perto do fim do ciclo.
 */
function davez_public_ticket_expires_at(
    OperationalContext $context,
    ?DateTimeInterface $issuedAt = null
): DateTimeImmutable {
    $issued = $issuedAt === null
        ? $context->reference()
        : DateTimeImmutable::createFromInterface($issuedAt);
    $cycleEnd = $context->end();

    if ($issued < $context->start() || $issued >= $cycleEnd) {
        throw new InvalidArgumentException(
            'O ticket deve ser criado dentro do ciclo operacional.'
        );
    }

    $maximum = $issued->setTimestamp(
        $issued->getTimestamp() + DAVEZ_PUBLIC_TICKET_TTL_SECONDS
    );

    if ($maximum > $cycleEnd) {
        throw new RuntimeException(
            'Não há dez minutos disponíveis neste ciclo operacional.'
        );
    }

    return $maximum;
}

/**
 * Reduz a exposição pública para primeiro nome e inicial do último sobrenome.
 */
function davez_public_display_name(string $name): string
{
    if (
        $name === ''
        || preg_match('//u', $name) !== 1
        || preg_match('/[\x00-\x1F\x7F]/', $name) === 1
    ) {
        throw new InvalidArgumentException('Nome público inválido.');
    }

    $normalized = preg_replace('/\s+/u', ' ', trim($name));

    if ($normalized === null || $normalized === '') {
        throw new InvalidArgumentException('Nome público inválido.');
    }

    $parts = preg_split('/\s+/u', $normalized);

    if (!is_array($parts) || $parts === []) {
        throw new InvalidArgumentException('Nome público inválido.');
    }

    if (count($parts) === 1) {
        return davez_first_utf8_character($parts[0]) . '.';
    }

    return $parts[0]
        . ' '
        . davez_first_utf8_character($parts[count($parts) - 1])
        . '.';
}

function davez_first_utf8_character(string $value): string
{
    $matched = preg_match('/\A./us', $value, $matches);

    if ($matched !== 1) {
        throw new InvalidArgumentException('Nome público inválido.');
    }

    return $matches[0];
}

/**
 * Retorna o nome seguro do cookie para o transporte atual.
 *
 * HTTP é permitido exclusivamente em desenvolvimento local com origem e
 * destino loopback. Qualquer HTTP real é recusado.
 */
function davez_public_identity_cookie_name(): string
{
    if (davez_is_https_request()) {
        return DAVEZ_PUBLIC_IDENTITY_COOKIE_HTTPS;
    }

    if (davez_is_local_http_request()) {
        return DAVEZ_PUBLIC_IDENTITY_COOKIE_LOCAL;
    }

    throw new RuntimeException(
        'A identidade pública exige HTTPS fora do ambiente local.'
    );
}

function davez_is_local_http_request(): bool
{
    $rawHost = strtolower(
        trim((string) (
            $_SERVER['HTTP_HOST']
            ?? $_SERVER['SERVER_NAME']
            ?? ''
        ))
    );
    $remoteAddress = strtolower(
        trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''))
    );

    if ($rawHost === '' || $remoteAddress === '') {
        return false;
    }

    if ($rawHost[0] === '[') {
        $closingBracket = strpos($rawHost, ']');
        $host = $closingBracket === false
            ? ''
            : substr($rawHost, 1, $closingBracket - 1);
    } else {
        $hostParts = explode(':', $rawHost, 2);
        $host = rtrim($hostParts[0], '.');
    }

    return in_array($host, ['localhost', '127.0.0.1', '::1'], true)
        && in_array($remoteAddress, ['127.0.0.1', '::1'], true);
}

/**
 * @return array{
 *   expires: int,
 *   path: string,
 *   secure: bool,
 *   httponly: bool,
 *   samesite: 'Strict'
 * }
 */
function davez_public_identity_cookie_options(int $expires): array
{
    // Resolve e valida o transporte antes de construir as opções.
    davez_public_identity_cookie_name();

    return [
        'expires' => $expires,
        'path' => '/',
        'secure' => davez_is_https_request(),
        'httponly' => true,
        'samesite' => 'Strict',
    ];
}

function davez_set_public_identity_cookie(
    string $token,
    DateTimeInterface $expiresAt
): void {
    davez_decode_public_session_token($token);

    if ($expiresAt->getTimestamp() <= time()) {
        throw new InvalidArgumentException(
            'A expiração do cookie deve estar no futuro.'
        );
    }

    if (headers_sent($sourceFile, $sourceLine)) {
        throw new RuntimeException(sprintf(
            'O cookie deve ser enviado antes da saída HTTP (%s:%d).',
            $sourceFile,
            $sourceLine
        ));
    }

    $cookieName = davez_public_identity_cookie_name();

    if (!setcookie(
        $cookieName,
        $token,
        davez_public_identity_cookie_options($expiresAt->getTimestamp())
    )) {
        throw new RuntimeException(
            'Não foi possível criar o cookie de identidade pública.'
        );
    }
}

function davez_clear_public_identity_cookie(): void
{
    if (headers_sent($sourceFile, $sourceLine)) {
        throw new RuntimeException(sprintf(
            'O cookie deve ser removido antes da saída HTTP (%s:%d).',
            $sourceFile,
            $sourceLine
        ));
    }

    $cookieName = davez_public_identity_cookie_name();

    if (!setcookie(
        $cookieName,
        '',
        davez_public_identity_cookie_options(time() - 3600)
    )) {
        throw new RuntimeException(
            'Não foi possível remover o cookie de identidade pública.'
        );
    }
}

function davez_public_identity_cookie_token(): ?string
{
    $cookieName = davez_public_identity_cookie_name();
    $token = $_COOKIE[$cookieName] ?? null;

    if (!is_string($token) || strlen($token) > 64) {
        return null;
    }

    try {
        davez_decode_public_session_token($token);
    } catch (InvalidArgumentException $exception) {
        return null;
    }

    return $token;
}
