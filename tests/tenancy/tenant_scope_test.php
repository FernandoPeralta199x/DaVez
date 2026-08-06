<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Tenancy/TenantContext.php';
require_once dirname(__DIR__, 2) . '/src/Tenancy/TenantScope.php';

use DaVez\Tenancy\TenantContext;
use DaVez\Tenancy\TenantScope;

function ts_fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function ts_assert(bool $condition, string $message): void
{
    if (!$condition) {
        ts_fail($message);
    }
}

function ts_expect_throw(callable $fn, string $class, string $message): void
{
    try {
        $fn();
    } catch (Throwable $exception) {
        ts_assert(
            $exception instanceof $class,
            $message . ' Recebido: ' . get_class($exception)
        );
        return;
    }
    ts_fail($message . ' (nenhuma exceção lançada)');
}

$scope = new TenantScope(TenantContext::forTenantAdmin(5, 12));
ts_assert($scope->tenantId() === 5, 'tenantId do escopo.');
ts_assert($scope->predicate() === 'tenant_id = ?', 'Predicado sem alias.');
ts_assert($scope->predicate('c') === 'c.tenant_id = ?', 'Predicado com alias.');
ts_assert($scope->column() === 'tenant_id', 'Coluna sem alias.');
ts_assert($scope->column('fila') === 'fila.tenant_id', 'Coluna com alias.');

// Sempre placeholder: o valor do tenant nunca entra no SQL.
ts_assert(
    strpos($scope->predicate(), (string) $scope->tenantId()) === false,
    'O predicado não pode conter o valor do tenant (usa placeholder).'
);

// Alias inválido é rejeitado (defesa contra injeção via alias).
foreach (['1abc', 'a b', 'a;drop', 'a-b'] as $bad) {
    ts_expect_throw(
        static fn() => $scope->column($bad),
        InvalidArgumentException::class,
        "Alias invalido deveria ser rejeitado: {$bad}"
    );
}

// Escopo exige tenant: contexto de plataforma não pode virar escopo.
ts_expect_throw(
    static fn() => new TenantScope(TenantContext::forPlatform(9)),
    RuntimeException::class,
    'Escopo sem tenant deve ser negado.'
);

fwrite(STDOUT, 'tenant_scope_test: OK' . PHP_EOL);
