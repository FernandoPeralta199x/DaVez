<?php

declare(strict_types=1);

namespace DaVez\Security;

use InvalidArgumentException;
use RuntimeException;

/**
 * Hashing de senha administrativa (ADR-002).
 *
 * Usa Argon2id quando o runtime tiver suporte; cai para bcrypt como fallback
 * compatível. A senha bruta nunca é persistida. `needsRehash` permite migrar
 * hashes antigos para o algoritmo preferido no próximo login bem-sucedido.
 */
final class PasswordHasher
{
    /** Piso técnico. O 1º acesso ainda força a troca da senha temporária. */
    public const MIN_LENGTH = 10;

    private static function preferredAlgo(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    public static function hash(string $plain): string
    {
        self::assertStrong($plain);

        $hash = password_hash($plain, self::preferredAlgo());

        if (!is_string($hash) || $hash === '') {
            throw new RuntimeException('Não foi possível gerar o hash da senha.');
        }

        return $hash;
    }

    public static function verify(string $plain, string $hash): bool
    {
        if ($plain === '' || $hash === '') {
            return false;
        }

        return password_verify($plain, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::preferredAlgo());
    }

    public static function assertStrong(string $plain): void
    {
        if (strlen($plain) < self::MIN_LENGTH) {
            throw new InvalidArgumentException(
                'A senha deve ter ao menos ' . self::MIN_LENGTH . ' caracteres.'
            );
        }
    }
}
