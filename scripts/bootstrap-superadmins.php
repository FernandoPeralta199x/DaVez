<?php

declare(strict_types=1);

/**
 * Bootstrap seguro dos SUPER_ADMIN iniciais (ADR-002).
 *
 * Uso (com as variáveis de banco no ambiente):
 *   DB_HOST=127.0.0.1 DB_NAME=davez DB_USER=... DB_PASSWORD=... \
 *     php scripts/bootstrap-superadmins.php
 *
 * Para cada conta que ainda não existe, gera uma senha temporária aleatória,
 * grava SOMENTE o hash Argon2id com must_change_password=1, e imprime a senha
 * UMA vez para entrega por canal seguro. Nenhum segredo é persistido em texto,
 * nem fica no Git. Idempotente: pula contas já existentes.
 */

require_once __DIR__ . '/../src/Security/PasswordHasher.php';
require_once __DIR__ . '/../src/Database/UserStore.php';

use DaVez\Database\UserStore;
use DaVez\Security\PasswordHasher;

/** @var list<string> Logins dos SUPER_ADMIN iniciais (sem senha no código). */
$logins = ['FernandoPeralta', 'RobertMoura'];

function fatal(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function requiredEnv(string $name): string
{
    $value = getenv($name);
    if (!is_string($value) || trim($value) === '') {
        fatal("Variável de ambiente obrigatória ausente: {$name}");
    }
    return $value;
}

function generateTempPassword(): string
{
    // ~20 caracteres base64url: forte e digitável uma única vez.
    return rtrim(strtr(base64_encode(random_bytes(15)), '+/', '-_'), '=');
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn = new mysqli(
    requiredEnv('DB_HOST'),
    requiredEnv('DB_USER'),
    requiredEnv('DB_PASSWORD'),
    requiredEnv('DB_NAME')
);
if ($conn->connect_error) {
    fatal('Falha ao conectar ao banco de dados.');
}
$conn->set_charset('utf8mb4');

$store = new UserStore($conn);
$created = 0;

foreach ($logins as $login) {
    if ($store->findByLogin($login) !== null) {
        fwrite(STDOUT, "= {$login}: já existe, pulando." . PHP_EOL);
        continue;
    }

    $temp = generateTempPassword();
    $hash = PasswordHasher::hash($temp);
    $store->createUser(null, $login, null, $hash, 'SUPER_ADMIN', true);
    $created++;

    fwrite(STDOUT, "+ {$login}: criado como SUPER_ADMIN." . PHP_EOL);
    fwrite(STDOUT, "  senha temporária (troca obrigatória no 1º acesso): {$temp}" . PHP_EOL);
    fwrite(STDOUT, "  entregue por canal seguro e NÃO guarde esta senha." . PHP_EOL);
}

fwrite(STDOUT, "Concluído. Contas criadas nesta execução: {$created}." . PHP_EOL);
