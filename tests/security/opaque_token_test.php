<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Security/OpaqueToken.php';

use DaVez\Security\OpaqueToken;

function ot_fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function ot_assert(bool $condition, string $message): void
{
    if (!$condition) {
        ot_fail($message);
    }
}

function ot_expect_throw(callable $fn, string $class, string $message): void
{
    try {
        $fn();
    } catch (Throwable $exception) {
        ot_assert(
            $exception instanceof $class,
            $message . ' Recebido: ' . get_class($exception)
        );
        return;
    }
    ot_fail($message . ' (nenhuma exceção lançada)');
}

// Geração: formato válido, 43 caracteres Base64 URL-safe.
$token = OpaqueToken::generate();
ot_assert(OpaqueToken::isValid($token), 'O token gerado deve ser válido.');
ot_assert(strlen($token) === 43, 'O token deve ter 43 caracteres.');
ot_assert(
    preg_match('/\A[A-Za-z0-9_-]{43}\z/', $token) === 1,
    'O token deve ser Base64 URL-safe.'
);

// Aleatoriedade: dois tokens não colidem.
ot_assert(OpaqueToken::generate() !== OpaqueToken::generate(), 'Tokens devem ser aleatórios.');

// Validação de formato.
ot_assert(OpaqueToken::isValid('curto') === false, 'Token curto é inválido.');
ot_assert(OpaqueToken::isValid(str_repeat('a', 44)) === false, 'Token longo é inválido.');
ot_assert(
    OpaqueToken::isValid(str_repeat('+', 43)) === false,
    'Caracteres fora do alfabeto URL-safe são inválidos.'
);

// Hash: 32 bytes binários, estável e diferente por token; rejeita malformado.
$hash = OpaqueToken::hash($token);
ot_assert(strlen($hash) === 32, 'O hash deve ter 32 bytes binários.');
ot_assert(
    hash_equals($hash, OpaqueToken::hash($token)),
    'O hash deve ser estável para o mesmo token.'
);
ot_assert(
    OpaqueToken::hash(OpaqueToken::generate()) !== $hash,
    'Tokens diferentes devem gerar hashes diferentes.'
);
ot_expect_throw(
    static fn() => OpaqueToken::hash('token-invalido'),
    InvalidArgumentException::class,
    'Hash de token malformado deve ser rejeitado.'
);

fwrite(STDOUT, 'opaque_token_test: OK' . PHP_EOL);
