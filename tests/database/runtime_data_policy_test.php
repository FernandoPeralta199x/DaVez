<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$endpoints = [
    'admin.php',
    'checkin.php',
    'relogin.php',
    'recover.php',
    'public_logout.php',
    'session_info.php',
    'DaVez/entrar.php',
    'DaVez/listar.php',
    'DaVez/listar_admin.php',
    'DaVez/reordenar.php',
    'DaVez/sair.php',
];
$forbiddenPatterns = [
    '/\bCURDATE\s*\(/i' => 'CURDATE',
    '/\b(?:CREATE|ALTER|DROP)\s+TABLE\b/i' => 'DDL em request',
    '/\bSHOW\s+(?:COLUMNS|TABLES)\b/i' => 'introspecção de schema',
    '/function\s+(?:get_operational|ensure_token|get_token|generate_token)/i'
        => 'helper duplicado',
    '/md5\s*\(\s*uniqid/i' => 'identificador previsível',
];

foreach ($endpoints as $relativePath) {
    $source = file_get_contents($root . DIRECTORY_SEPARATOR . $relativePath);
    if ($source === false) {
        fwrite(STDERR, "runtime_data_policy_test: FAIL - {$relativePath}" . PHP_EOL);
        exit(1);
    }

    foreach ($forbiddenPatterns as $pattern => $label) {
        if (preg_match($pattern, $source) === 1) {
            fwrite(
                STDERR,
                "runtime_data_policy_test: FAIL - {$label} em {$relativePath}"
                    . PHP_EOL
            );
            exit(1);
        }
    }
}

$admin = file_get_contents($root . '/admin.php');
$queueReorder = file_get_contents($root . '/DaVez/reordenar.php');
$required = [
    [$admin, "FOR UPDATE", 'admin sem bloqueio pessimista'],
    [$admin, "davez_atomic_order_allocator", 'admin sem alocador atômico'],
    [$admin, "ReportSnapshot::build", 'limpeza sem snapshot compartilhado'],
    [$queueReorder, "QueueReorder::assertExactSet", 'reordenação sem conjunto exato'],
];

foreach ($required as [$source, $needle, $message]) {
    if (!is_string($source) || strpos($source, $needle) === false) {
        fwrite(STDERR, "runtime_data_policy_test: FAIL - {$message}" . PHP_EOL);
        exit(1);
    }
}

echo 'runtime_data_policy_test: OK' . PHP_EOL;
