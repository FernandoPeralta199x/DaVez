<?php

declare(strict_types=1);

namespace DaVez\Security;

use InvalidArgumentException;

/**
 * Token opaco de sessão: 32 bytes aleatórios em Base64 URL-safe.
 *
 * O token bruto vive apenas no cookie do cliente; o banco guarda somente o
 * hash SHA-256 binário (BINARY(32)). Assim, um vazamento do banco não expõe
 * tokens válidos. Usado pelas sessões administrativas (admin_sessions).
 */
final class OpaqueToken
{
    private const BYTES = 32;

    /** Formato: 43 caracteres Base64 URL-safe (32 bytes sem padding). */
    private const FORMAT = '/\A[A-Za-z0-9_-]{43}\z/';

    public static function generate(): string
    {
        return rtrim(
            strtr(base64_encode(random_bytes(self::BYTES)), '+/', '-_'),
            '='
        );
    }

    public static function isValid(string $token): bool
    {
        return preg_match(self::FORMAT, $token) === 1;
    }

    /** Hash SHA-256 binário (32 bytes) para armazenar e consultar. */
    public static function hash(string $token): string
    {
        if (!self::isValid($token)) {
            throw new InvalidArgumentException('Token opaco malformado.');
        }

        return hash('sha256', $token, true);
    }
}
