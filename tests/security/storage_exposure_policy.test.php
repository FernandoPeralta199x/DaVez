<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/log.php';

function fail_storage_policy(string $message): never
{
    fwrite(STDERR, "storage_exposure_policy: FAIL - {$message}" . PHP_EOL);
    exit(1);
}

function assert_storage_policy(bool $condition, string $message): void
{
    if (!$condition) {
        fail_storage_policy($message);
    }
}

$root = dirname(__DIR__, 2);
$htaccess = (string) file_get_contents($root . '/.htaccess');
$buildRelease = (string) file_get_contents($root . '/scripts/build-release.ps1');
$environmentExample = (string) file_get_contents($root . '/.env.example');

foreach (['logs', 'reports', 'database', 'src', 'tests'] as $privateDirectory) {
    assert_storage_policy(
        str_contains($htaccess, $privateDirectory),
        ".htaccess não bloqueia o diretório {$privateDirectory}."
    );
}

foreach ([
    'X-Content-Type-Options',
    'X-Frame-Options',
    'Referrer-Policy',
    'Permissions-Policy',
] as $securityHeader) {
    assert_storage_policy(
        str_contains($htaccess, $securityHeader),
        ".htaccess não define o header {$securityHeader}."
    );
}

assert_storage_policy(
    str_contains($buildRelease, "'.htaccess'"),
    'O artefato de release não inclui a proteção do Apache.'
);
assert_storage_policy(
    str_contains($environmentExample, 'APP_LOG_PATH='),
    '.env.example não documenta APP_LOG_PATH.'
);

$temporaryDirectory = sys_get_temp_dir()
    . DIRECTORY_SEPARATOR
    . 'davez-private-log-'
    . bin2hex(random_bytes(8));
$temporaryLog = $temporaryDirectory . DIRECTORY_SEPARATOR . 'application.log';
putenv('APP_LOG_PATH=' . $temporaryLog);
$_SERVER['DOCUMENT_ROOT'] = $root;

log_event('STORAGE_POLICY_TEST', ['ordem' => 1]);

assert_storage_policy(
    is_file($temporaryLog),
    'O logger não gravou no caminho privado absoluto.'
);
assert_storage_policy(
    !str_contains((string) file_get_contents($temporaryLog), $temporaryDirectory),
    'O evento expôs o caminho privado.'
);

putenv('APP_LOG_PATH=' . $root . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'forbidden.log');
assert_storage_policy(
    resolve_private_log_file() === null,
    'O logger aceitou caminho dentro do webroot.'
);

@unlink($temporaryLog);
@rmdir($temporaryDirectory);
putenv('APP_LOG_PATH');

echo 'storage_exposure_policy: OK' . PHP_EOL;
