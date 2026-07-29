<?php

declare(strict_types=1);

$sourcePath = __DIR__ . '/../../src/Database/SettingsTokenCycle.php';
$source = file_get_contents($sourcePath);

if ($source === false) {
    fwrite(
        STDERR,
        'settings_token_cycle_cas_policy_test: FAIL - helper ausente.'
            . PHP_EOL
    );
    exit(1);
}

$requirements = [
    'AND token=?' => 'o token observado não participa do compare-and-set',
    'AND token_data <=> ?' => 'token_data não possui comparação null-safe',
    '$statement->affected_rows === 1'
        => 'o vencedor do compare-and-set não é identificado',
    '$settings = $this->loadSettings()'
        => 'o valor vencedor não é recarregado após conflito',
];

foreach ($requirements as $needle => $message) {
    if (strpos($source, $needle) === false) {
        fwrite(
            STDERR,
            "settings_token_cycle_cas_policy_test: FAIL - {$message}."
                . PHP_EOL
        );
        exit(1);
    }
}

if (preg_match('/WHERE\s+id=1\s*["\']/', $source) === 1) {
    fwrite(
        STDERR,
        'settings_token_cycle_cas_policy_test: FAIL - update incondicional.'
            . PHP_EOL
    );
    exit(1);
}

echo 'settings_token_cycle_cas_policy_test: OK' . PHP_EOL;
