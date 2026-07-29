<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Security/RateLimiter.php';

function rate_limiter_fail(string $message, string $directory): never
{
    foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($directory);

    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$storageDirectory = sys_get_temp_dir()
    . DIRECTORY_SEPARATOR
    . 'davez-rate-limit-'
    . bin2hex(random_bytes(8));
$secret = str_repeat('sintetico-', 4);
$subject = 'network:203.0.113.99';

try {
    davez_rate_limit_directory('storage-relativo-proibido');
    rate_limiter_fail(
        'Um caminho relativo foi aceito para o rate limiter.',
        $storageDirectory
    );
} catch (RuntimeException $exception) {
    // Comportamento esperado: nada é criado dentro do web root.
}

$first = davez_rate_limit_consume(
    'admin-login',
    $subject,
    2,
    60,
    $storageDirectory,
    $secret,
    1000
);
$second = davez_rate_limit_consume(
    'admin-login',
    $subject,
    2,
    60,
    $storageDirectory,
    $secret,
    1001
);
$third = davez_rate_limit_consume(
    'admin-login',
    $subject,
    2,
    60,
    $storageDirectory,
    $secret,
    1002
);

if (
    !$first['allowed']
    || !$second['allowed']
    || $third['allowed']
    || $third['retry_after'] !== 58
) {
    rate_limiter_fail(
        'A janela de rate limiting não foi aplicada corretamente.',
        $storageDirectory
    );
}

$files = glob($storageDirectory . DIRECTORY_SEPARATOR . '*.json') ?: [];

if (count($files) !== 1) {
    rate_limiter_fail(
        'O rate limiter não criou exatamente um estado opaco.',
        $storageDirectory
    );
}

$fileName = basename($files[0]);
$rawState = file_get_contents($files[0]);

if (
    preg_match('/\A[a-f0-9]{64}\.json\z/', $fileName) !== 1
    || $rawState === false
    || str_contains($rawState, $subject)
    || str_contains($rawState, 'admin-login')
) {
    rate_limiter_fail(
        'O estado do rate limiter expôs bucket ou sujeito.',
        $storageDirectory
    );
}

$newWindow = davez_rate_limit_consume(
    'admin-login',
    $subject,
    2,
    60,
    $storageDirectory,
    $secret,
    1060
);

if (!$newWindow['allowed'] || $newWindow['remaining'] !== 1) {
    rate_limiter_fail(
        'A nova janela de rate limiting não foi iniciada.',
        $storageDirectory
    );
}

foreach ($files as $file) {
    @unlink($file);
}
@rmdir($storageDirectory);

fwrite(STDOUT, 'rate_limiter_test: OK' . PHP_EOL);
