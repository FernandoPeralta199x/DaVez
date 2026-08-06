<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Security/PasswordHasher.php';

use DaVez\Security\PasswordHasher;

function ph_fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function ph_assert(bool $condition, string $message): void
{
    if (!$condition) {
        ph_fail($message);
    }
}

function ph_expect_throw(callable $fn, string $class, string $message): void
{
    try {
        $fn();
    } catch (Throwable $exception) {
        ph_assert(
            $exception instanceof $class,
            $message . ' Recebido: ' . get_class($exception)
        );
        return;
    }
    ph_fail($message . ' (nenhuma exceção lançada)');
}

$plain = 'Senha-Forte-123';
$hash = PasswordHasher::hash($plain);

// O hash nunca é a senha em texto e é verificável.
ph_assert($hash !== $plain && strlen($hash) >= 20, 'O hash não deve ser texto puro.');
ph_assert(strpos($hash, $plain) === false, 'O hash não pode conter a senha bruta.');
ph_assert(PasswordHasher::verify($plain, $hash) === true, 'A senha correta deve verificar.');
ph_assert(PasswordHasher::verify('senha-errada-000', $hash) === false, 'Senha errada não verifica.');
ph_assert(PasswordHasher::verify('', $hash) === false, 'Senha vazia não verifica.');
ph_assert(PasswordHasher::verify($plain, '') === false, 'Hash vazio não verifica.');

// Hashes distintos para a mesma senha (salt aleatório) — ambos verificam.
$hash2 = PasswordHasher::hash($plain);
ph_assert($hash2 !== $hash, 'Dois hashes da mesma senha devem diferir (salt).');
ph_assert(PasswordHasher::verify($plain, $hash2) === true, 'O segundo hash também verifica.');

// needsRehash é falso para o hash recém-gerado no algoritmo preferido.
ph_assert(PasswordHasher::needsRehash($hash) === false, 'Hash recente não precisa de rehash.');

// Política mínima de comprimento.
ph_expect_throw(
    static fn() => PasswordHasher::hash('curta'),
    InvalidArgumentException::class,
    'Senha curta deve ser rejeitada.'
);

fwrite(STDOUT, 'password_hasher_test: OK' . PHP_EOL);
