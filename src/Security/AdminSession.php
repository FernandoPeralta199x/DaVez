<?php

declare(strict_types=1);

require_once __DIR__ . '/OpaqueToken.php';
require_once __DIR__ . '/PublicIdentity.php';

use DaVez\Security\OpaqueToken;

/**
 * Cookie da sessão administrativa (ADR-002), espelhando as proteções do cookie
 * público: prefixo __Host- em HTTPS, nome exclusivo de desenvolvimento em
 * loopback, Secure/HttpOnly/SameSite=Strict e path raiz. O cookie carrega só o
 * token opaco; o banco (admin_sessions) guarda apenas o hash SHA-256.
 */

const DAVEZ_ADMIN_COOKIE_HTTPS = '__Host-davez_admin';
const DAVEZ_ADMIN_COOKIE_LOCAL = 'davez_admin_dev';

function davez_admin_cookie_name(): string
{
    if (davez_is_https_request()) {
        return DAVEZ_ADMIN_COOKIE_HTTPS;
    }

    if (davez_is_local_http_request()) {
        return DAVEZ_ADMIN_COOKIE_LOCAL;
    }

    throw new RuntimeException(
        'A sessão administrativa exige HTTPS fora do ambiente local.'
    );
}

/**
 * @return array{
 *   expires: int, path: string, secure: bool,
 *   httponly: bool, samesite: 'Strict'
 * }
 */
function davez_admin_cookie_options(int $expires): array
{
    davez_admin_cookie_name();

    return [
        'expires' => $expires,
        'path' => '/',
        'secure' => davez_is_https_request(),
        'httponly' => true,
        'samesite' => 'Strict',
    ];
}

function davez_set_admin_cookie(
    string $token,
    DateTimeInterface $expiresAt
): void {
    if (!OpaqueToken::isValid($token)) {
        throw new InvalidArgumentException('Token de sessão administrativa inválido.');
    }

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

    if (!setcookie(
        davez_admin_cookie_name(),
        $token,
        davez_admin_cookie_options($expiresAt->getTimestamp())
    )) {
        throw new RuntimeException(
            'Não foi possível criar o cookie de sessão administrativa.'
        );
    }
}

function davez_clear_admin_cookie(): void
{
    if (headers_sent($sourceFile, $sourceLine)) {
        throw new RuntimeException(sprintf(
            'O cookie deve ser removido antes da saída HTTP (%s:%d).',
            $sourceFile,
            $sourceLine
        ));
    }

    if (!setcookie(
        davez_admin_cookie_name(),
        '',
        davez_admin_cookie_options(time() - 3600)
    )) {
        throw new RuntimeException(
            'Não foi possível remover o cookie de sessão administrativa.'
        );
    }
}

function davez_admin_cookie_token(): ?string
{
    $token = $_COOKIE[davez_admin_cookie_name()] ?? null;

    if (!is_string($token) || !OpaqueToken::isValid($token)) {
        return null;
    }

    return $token;
}
