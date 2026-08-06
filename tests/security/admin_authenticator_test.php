<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Security/AdminAuthenticator.php';

use DaVez\Security\AdminAuthenticator;
use DaVez\Security\AdminAuthResult;
use DaVez\Security\PasswordHasher;
use DaVez\Security\UserRepository;
use DaVez\Tenancy\TenantContext;

/** Repositório em memória para testar o fluxo de login sem banco. */
final class FakeUserRepository implements UserRepository
{
    /** @var array<string, array<string, mixed>> */
    public $byLogin = [];
    /** @var list<int> */
    public $failedCalls = [];
    /** @var list<int> */
    public $clearedCalls = [];
    /** @var list<int> */
    public $rehashCalls = [];

    public function findByLogin(string $login): ?array
    {
        return $this->byLogin[$login] ?? null;
    }

    public function registerFailedLogin(
        int $userId,
        int $maxAttempts,
        DateTimeInterface $lockUntil,
        DateTimeInterface $now
    ): void {
        $this->failedCalls[] = $userId;
    }

    public function clearFailedLogins(int $userId, DateTimeInterface $now): void
    {
        $this->clearedCalls[] = $userId;
    }

    public function updatePasswordHash(
        int $userId,
        string $newHash,
        DateTimeInterface $now
    ): void {
        $this->rehashCalls[] = $userId;
    }
}

function aa_fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function aa_assert(bool $condition, string $message): void
{
    if (!$condition) {
        aa_fail($message);
    }
}

$now = new DateTimeImmutable('2026-08-05 12:00:00');
$validPlain = 'Senha-Forte-123';
$hash = PasswordHasher::hash($validPlain);

function baseUser(array $overrides): array
{
    return array_merge([
        'id' => 10,
        'tenant_id' => null,
        'login' => 'user',
        'password_hash' => '',
        'role' => 'SUPER_ADMIN',
        'must_change_password' => 0,
        'mfa_secret' => null,
        'failed_attempts' => 0,
        'locked_until' => null,
        'status' => 'active',
    ], $overrides);
}

// 1) Login inexistente -> INVALID.
$repo = new FakeUserRepository();
$auth = new AdminAuthenticator($repo);
$r = $auth->authenticate('ninguem', $validPlain, $now);
aa_assert($r->status() === AdminAuthResult::INVALID_CREDENTIALS, 'Login inexistente deve ser inválido.');

// 2) Senha errada -> INVALID + registra falha.
$repo = new FakeUserRepository();
$repo->byLogin['super'] = baseUser(['id' => 10, 'login' => 'super', 'password_hash' => $hash]);
$auth = new AdminAuthenticator($repo);
$r = $auth->authenticate('super', 'senha-errada-000', $now);
aa_assert($r->status() === AdminAuthResult::INVALID_CREDENTIALS, 'Senha errada deve ser inválida.');
aa_assert($repo->failedCalls === [10], 'Deve registrar a falha de login.');

// 3) Conta bloqueada -> LOCKED, mesmo com a senha correta.
$repo = new FakeUserRepository();
$repo->byLogin['super'] = baseUser([
    'id' => 10, 'login' => 'super', 'password_hash' => $hash,
    'locked_until' => '2026-08-05 13:00:00',
]);
$auth = new AdminAuthenticator($repo);
$r = $auth->authenticate('super', $validPlain, $now);
aa_assert($r->status() === AdminAuthResult::LOCKED, 'Conta bloqueada deve retornar LOCKED.');
aa_assert($repo->failedCalls === [], 'Conta bloqueada não verifica a senha.');

// 4) Senha correta, mas usuário suspenso -> SUSPENDED.
$repo = new FakeUserRepository();
$repo->byLogin['super'] = baseUser([
    'id' => 10, 'login' => 'super', 'password_hash' => $hash, 'status' => 'suspended',
]);
$auth = new AdminAuthenticator($repo);
$r = $auth->authenticate('super', $validPlain, $now);
aa_assert($r->status() === AdminAuthResult::SUSPENDED, 'Usuário suspenso deve retornar SUSPENDED.');

// 5) Sucesso SUPER_ADMIN -> contexto de plataforma + limpa falhas + mustChange.
$repo = new FakeUserRepository();
$repo->byLogin['super'] = baseUser([
    'id' => 10, 'login' => 'super', 'password_hash' => $hash,
    'role' => 'SUPER_ADMIN', 'tenant_id' => null, 'must_change_password' => 1,
]);
$auth = new AdminAuthenticator($repo);
$r = $auth->authenticate('super', $validPlain, $now);
aa_assert($r->isSuccess(), 'SUPER_ADMIN com senha certa deve autenticar.');
aa_assert($r->context()->isPlatform() === true, 'SUPER_ADMIN deve ter contexto de plataforma.');
aa_assert($r->context()->is(TenantContext::ROLE_SUPER_ADMIN), 'Papel SUPER_ADMIN no contexto.');
aa_assert($r->userId() === 10, 'userId no resultado.');
aa_assert($r->mustChangePassword() === true, 'Deve exigir troca de senha no 1º acesso.');
aa_assert($repo->clearedCalls === [10], 'Deve limpar as falhas no sucesso.');

// 6) Sucesso ADMIN_EMPRESA -> contexto preso ao tenant.
$repo = new FakeUserRepository();
$repo->byLogin['emp'] = baseUser([
    'id' => 22, 'login' => 'emp', 'password_hash' => $hash,
    'role' => 'ADMIN_EMPRESA', 'tenant_id' => 5, 'must_change_password' => 0,
]);
$auth = new AdminAuthenticator($repo);
$r = $auth->authenticate('emp', $validPlain, $now);
aa_assert($r->isSuccess(), 'ADMIN_EMPRESA com senha certa deve autenticar.');
aa_assert($r->context()->tenantId() === 5, 'Contexto preso ao tenant 5.');
aa_assert($r->context()->is(TenantContext::ROLE_ADMIN_EMPRESA), 'Papel ADMIN_EMPRESA no contexto.');
aa_assert($r->mustChangePassword() === false, 'Sem troca obrigatória quando a flag é 0.');

fwrite(STDOUT, 'admin_authenticator_test: OK' . PHP_EOL);
