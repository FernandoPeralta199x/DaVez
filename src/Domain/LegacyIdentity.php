<?php

declare(strict_types=1);

namespace DaVez\Domain;

use InvalidArgumentException;

final class LegacyIdentity
{
    private const TOKEN_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public static function tokenCode(int $length = 6): string
    {
        if ($length < 1 || $length > 16) {
            throw new InvalidArgumentException(
                'O tamanho do código legado deve estar entre 1 e 16.'
            );
        }

        $maximumIndex = strlen(self::TOKEN_ALPHABET) - 1;
        $token = '';

        for ($index = 0; $index < $length; $index++) {
            $token .= self::TOKEN_ALPHABET[
                random_int(0, $maximumIndex)
            ];
        }

        return $token;
    }

    public static function clientId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
