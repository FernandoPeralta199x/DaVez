<?php

declare(strict_types=1);

function fail_identity_v2_schema_test(string $message): void
{
    fwrite(
        STDERR,
        "identity_v2_schema_contract_test: FAIL - {$message}" . PHP_EOL
    );
    exit(1);
}

function assert_identity_v2_schema(bool $condition, string $message): void
{
    if (!$condition) {
        fail_identity_v2_schema_test($message);
    }
}

function read_identity_v2_file(string $path): string
{
    assert_identity_v2_schema(
        is_file($path),
        basename($path) . ' não foi encontrado.'
    );

    $content = (string) file_get_contents($path);
    assert_identity_v2_schema(
        trim($content) !== '',
        basename($path) . ' está vazio.'
    );

    return $content;
}

function assert_identity_v2_fragments(
    string $content,
    array $fragments,
    string $source
): void {
    foreach ($fragments as $fragment) {
        assert_identity_v2_schema(
            stripos($content, $fragment) !== false,
            "{$source} não contém o contrato: {$fragment}"
        );
    }
}

$root = dirname(__DIR__, 2);
$migrationDirectory = $root . '/database/migrations';
$expectedMigrations = [
    '001_create_settings.sql',
    '002_create_checkins.sql',
    '003_create_fila_da_vez.sql',
    '004_create_reports.sql',
    '005_add_checkins_operational_date.sql',
    '006_create_admission_tickets.sql',
    '007_create_public_sessions.sql',
    '008_link_queue_to_checkins.sql',
];
$actualMigrations = array_map(
    'basename',
    glob($migrationDirectory . '/*.sql') ?: []
);
sort($actualMigrations, SORT_STRING);

assert_identity_v2_schema(
    $actualMigrations === $expectedMigrations,
    'As migrations devem permanecer numeradas continuamente de 001 a 008.'
);

$migrationContents = [];
foreach ($expectedMigrations as $migration) {
    $migrationContents[$migration] = read_identity_v2_file(
        $migrationDirectory . '/' . $migration
    );
}

$identityMigrations = implode(
    PHP_EOL,
    array_slice($migrationContents, 4, 4, true)
);

assert_identity_v2_schema(
    preg_match('/\b(?:DROP|TRUNCATE)\b/i', $identityMigrations) !== 1,
    'As migrations v2 não podem conter DDL destrutivo.'
);
assert_identity_v2_schema(
    preg_match(
        '/\b(?:raw_token|plain_token|ticket_code|ticket_value)\b/i',
        $identityMigrations
    ) !== 1,
    'As migrations v2 não podem persistir credencial bruta.'
);

assert_identity_v2_fragments(
    $migrationContents['005_add_checkins_operational_date.sql'],
    [
        'ADD COLUMN operational_date DATE NULL',
        'UNIQUE KEY uniq_checkins_id_operational_date',
        'operational_date',
    ],
    'migration 005'
);

assert_identity_v2_fragments(
    $migrationContents['006_create_admission_tickets.sql'],
    [
        'CREATE TABLE IF NOT EXISTS admission_tickets',
        'ticket_hash BINARY(32) NOT NULL',
        'UNIQUE KEY uniq_admission_ticket_hash',
        'INTERVAL 10 MINUTE',
        "purpose IN ('checkin', 'recovery')",
        'FOREIGN KEY (checkin_id, operational_date)',
        'REFERENCES checkins (id, operational_date)',
        'consumed_at IS NULL',
        'revoked_at IS NULL',
        'ON DELETE RESTRICT',
    ],
    'migration 006'
);

assert_identity_v2_fragments(
    $migrationContents['007_create_public_sessions.sql'],
    [
        'CREATE TABLE IF NOT EXISTS public_sessions',
        'token_hash BINARY(32) NOT NULL',
        'UNIQUE KEY uniq_public_session_token_hash',
        'active_slot TINYINT UNSIGNED NULL DEFAULT 1',
        'UNIQUE KEY uniq_public_session_active_device',
        '(checkin_id,',
        'active_slot',
        'FOREIGN KEY (checkin_id, operational_date)',
        'REFERENCES checkins (id, operational_date)',
        'INTERVAL 24 HOUR',
        'revocation_reason',
        'ON DELETE RESTRICT',
    ],
    'migration 007'
);

assert_identity_v2_fragments(
    $migrationContents['008_link_queue_to_checkins.sql'],
    [
        'ADD COLUMN checkin_id BIGINT UNSIGNED NULL',
        'MODIFY COLUMN client_id VARCHAR(64) NULL',
        'UNIQUE KEY uniq_fila_dia_checkin',
        'FOREIGN KEY (checkin_id, dia)',
        'REFERENCES checkins (id, operational_date)',
        'client_id IS NOT NULL OR checkin_id IS NOT NULL',
        'ON DELETE RESTRICT',
    ],
    'migration 008'
);

$schema = read_identity_v2_file($root . '/database/schema.sql');
assert_identity_v2_fragments(
    $schema,
    [
        'operational_date DATE NULL',
        'CREATE TABLE IF NOT EXISTS admission_tickets',
        'ticket_hash BINARY(32) NOT NULL',
        'INTERVAL 10 MINUTE',
        'CREATE TABLE IF NOT EXISTS public_sessions',
        'token_hash BINARY(32) NOT NULL',
        'UNIQUE KEY uniq_public_session_active_device',
        'UNIQUE KEY uniq_fila_dia_checkin',
        'CONSTRAINT fk_fila_checkin_cycle',
    ],
    'database/schema.sql'
);

$operations = read_identity_v2_file(
    $root . '/docs/DATABASE_OPERATIONS.md'
);
assert_identity_v2_fragments(
    $operations,
    [
        '005_add_checkins_operational_date.sql',
        '006_create_admission_tickets.sql',
        '007_create_public_sessions.sql',
        '008_link_queue_to_checkins.sql',
        '## Forward rollback',
        'ON DELETE RESTRICT',
        'não faça backfill de `operational_date`',
        'A limpeza atual não pode operar sobre',
    ],
    'docs/DATABASE_OPERATIONS.md'
);

echo 'identity_v2_schema_contract_test: OK' . PHP_EOL;
