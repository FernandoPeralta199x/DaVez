<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Config/FeatureFlags.php';

use DaVez\Config\FeatureFlags;

function feature_flags_fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function feature_flags_assert(bool $condition, string $message): void
{
    if (!$condition) {
        feature_flags_fail($message);
    }
}

// Ponto de partida limpo, sem herdar estado do ambiente.
putenv('FEATURE_MULTI_TENANT');
putenv('FEATURE_ADMIN_USERS_DB');

// 1) Flag conhecida sem env → desligada (padrão seguro).
feature_flags_assert(
    FeatureFlags::enabled('multi_tenant') === false,
    'Uma flag conhecida sem env deveria nascer desligada.'
);

// 2) Flag desconhecida ou vazia → sempre desligada (negar por padrão).
feature_flags_assert(
    FeatureFlags::enabled('inexistente') === false,
    'Uma flag desconhecida deveria retornar false.'
);
feature_flags_assert(
    FeatureFlags::enabled('') === false,
    'Uma flag vazia deveria retornar false.'
);

// 3) Ativação por env com valores verdadeiros (case-insensitive, com espaços).
foreach (['1', 'true', 'on', 'yes', 'YES', ' On '] as $truthy) {
    putenv('FEATURE_MULTI_TENANT=' . $truthy);
    feature_flags_assert(
        FeatureFlags::enabled('multi_tenant') === true,
        "O valor verdadeiro '{$truthy}' deveria ligar a flag."
    );
}

// 4) Valores falsos ou ambíguos mantêm a flag desligada.
foreach (['0', 'false', 'off', 'no', '', 'talvez'] as $falsy) {
    putenv('FEATURE_MULTI_TENANT=' . $falsy);
    feature_flags_assert(
        FeatureFlags::enabled('multi_tenant') === false,
        "O valor '{$falsy}' nao deveria ligar a flag."
    );
}

// 5) Independência entre flags e coerência de all()/known().
putenv('FEATURE_MULTI_TENANT=1');
putenv('FEATURE_ADMIN_USERS_DB');
$state = FeatureFlags::all();
feature_flags_assert(
    ($state['multi_tenant'] ?? null) === true
        && ($state['admin_users_db'] ?? null) === false,
    'As flags devem ser independentes entre si.'
);
feature_flags_assert(
    count($state) === count(FeatureFlags::known()),
    'all() deve refletir exatamente as flags conhecidas.'
);

// Limpeza final.
putenv('FEATURE_MULTI_TENANT');
putenv('FEATURE_ADMIN_USERS_DB');

fwrite(STDOUT, 'feature_flags_test: OK' . PHP_EOL);
