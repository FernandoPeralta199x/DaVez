<?php

declare(strict_types=1);

function fail_schema_contract_test(string $message): void
{
    fwrite(STDERR, "schema_contract_test: FAIL - {$message}" . PHP_EOL);
    exit(1);
}

function assert_schema_contract(bool $condition, string $message): void
{
    if (!$condition) {
        fail_schema_contract_test($message);
    }
}

$root = dirname(__DIR__, 2);
$schemaPath = $root . '/database/schema.sql';
$migrationPaths = [
    $root . '/database/migrations/001_create_settings.sql',
    $root . '/database/migrations/002_create_checkins.sql',
    $root . '/database/migrations/003_create_fila_da_vez.sql',
    $root . '/database/migrations/004_create_reports.sql',
];

assert_schema_contract(is_file($schemaPath), 'database/schema.sql não foi encontrado.');

$schema = (string) file_get_contents($schemaPath);
assert_schema_contract($schema !== '', 'database/schema.sql está vazio.');

foreach ($migrationPaths as $migrationPath) {
    assert_schema_contract(
        is_file($migrationPath),
        basename($migrationPath) . ' não foi encontrada.'
    );

    $migration = (string) file_get_contents($migrationPath);
    assert_schema_contract(
        stripos($migration, 'CREATE TABLE IF NOT EXISTS') !== false,
        basename($migrationPath) . ' não é idempotente para instalação nova.'
    );
    assert_schema_contract(
        !preg_match('/\b(DROP|TRUNCATE)\s+TABLE\b/i', $migration),
        basename($migrationPath) . ' contém DDL destrutivo.'
    );
}

$requiredTables = ['settings', 'checkins', 'fila_da_vez', 'reports'];
foreach ($requiredTables as $table) {
    assert_schema_contract(
        preg_match(
            '/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+' . preg_quote($table, '/') . '\s*\(/i',
            $schema
        ) === 1,
        "A tabela {$table} não está declarada no schema canônico."
    );
}

$requiredContractFragments = [
    'CONSTRAINT chk_settings_singleton',
    'CONSTRAINT chk_settings_positive_radius',
    'KEY idx_checkins_cycle_order',
    'KEY idx_checkins_client_cycle',
    'UNIQUE KEY uniq_fila_dia_client',
    "status IN ('na_fila', 'em_entrega')",
    'CONSTRAINT chk_reports_json',
    'ENGINE=InnoDB',
    'DEFAULT CHARACTER SET utf8mb4',
];

foreach ($requiredContractFragments as $fragment) {
    assert_schema_contract(
        stripos($schema, $fragment) !== false,
        "O contrato obrigatório não foi encontrado: {$fragment}"
    );
}

assert_schema_contract(
    !preg_match('/\b(password|passwd|secret|api[_-]?key)\s*=\s*[\'"][^\'"]+/i', $schema),
    'O schema contém um valor com aparência de credencial.'
);
assert_schema_contract(
    !preg_match('/\b(DROP|TRUNCATE)\s+TABLE\b/i', $schema),
    'O schema canônico contém DDL destrutivo.'
);

echo 'schema_contract_test: OK' . PHP_EOL;
