<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Tenancy/TenantContext.php';

use DaVez\Tenancy\TenantContext;

function tc_fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function tc_assert(bool $condition, string $message): void
{
    if (!$condition) {
        tc_fail($message);
    }
}

function tc_expect_throw(callable $fn, string $class, string $message): void
{
    try {
        $fn();
    } catch (Throwable $exception) {
        tc_assert(
            $exception instanceof $class,
            $message . ' Recebido: ' . get_class($exception)
        );
        return;
    }
    tc_fail($message . ' (nenhuma exceção lançada)');
}

// SUPER_ADMIN em contexto de plataforma.
$super = TenantContext::forPlatform(9);
tc_assert($super->isPlatform() === true, 'SUPER_ADMIN deveria ser plataforma.');
tc_assert($super->tenantId() === null, 'SUPER_ADMIN não tem tenant fixo.');
tc_assert($super->is(TenantContext::ROLE_SUPER_ADMIN), 'Papel SUPER_ADMIN.');
tc_assert($super->userId() === 9, 'userId preservado.');
tc_expect_throw(
    static fn() => $super->requireTenantId(),
    RuntimeException::class,
    'requireTenantId deveria negar sem tenant selecionado.'
);

// SUPER_ADMIN escolhe um tenant explicitamente.
$explicit = $super->forExplicitTenant(7);
tc_assert($explicit->requireTenantId() === 7, 'Tenant explícito aplicado.');
tc_assert(
    $explicit->is(TenantContext::ROLE_SUPER_ADMIN),
    'Continua SUPER_ADMIN após escolher tenant.'
);
tc_assert($explicit->isPlatform() === false, 'Com tenant explícito não é plataforma.');

// ADMIN_EMPRESA preso ao seu tenant.
$admin = TenantContext::forTenantAdmin(5, 12);
tc_assert(
    $admin->tenantId() === 5 && $admin->requireTenantId() === 5,
    'ADMIN_EMPRESA deve ficar no seu tenant.'
);
tc_assert($admin->is(TenantContext::ROLE_ADMIN_EMPRESA), 'Papel ADMIN_EMPRESA.');
tc_expect_throw(
    static fn() => $admin->forExplicitTenant(8),
    RuntimeException::class,
    'Só SUPER_ADMIN pode trocar de tenant explicitamente.'
);

// ENTREGADOR: identidade pública, sem user.
$driver = TenantContext::forDriver(5);
tc_assert($driver->requireTenantId() === 5, 'ENTREGADOR fica no seu tenant.');
tc_assert($driver->userId() === null, 'ENTREGADOR não tem user.');
tc_assert($driver->is(TenantContext::ROLE_ENTREGADOR), 'Papel ENTREGADOR.');

// Argumentos inválidos são rejeitados.
foreach ([0, -1] as $bad) {
    tc_expect_throw(
        static fn() => TenantContext::forTenantAdmin($bad, 1),
        InvalidArgumentException::class,
        'tenantId inválido deveria ser rejeitado.'
    );
    tc_expect_throw(
        static fn() => TenantContext::forPlatform($bad),
        InvalidArgumentException::class,
        'userId inválido deveria ser rejeitado.'
    );
    tc_expect_throw(
        static fn() => TenantContext::forDriver($bad),
        InvalidArgumentException::class,
        'tenant do entregador inválido deveria ser rejeitado.'
    );
}

fwrite(STDOUT, 'tenant_context_test: OK' . PHP_EOL);
