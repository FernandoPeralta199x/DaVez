<?php

declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../../recover.php');

if (!is_string($source)) {
    fwrite(STDERR, "recover_scope_regression_test: FAIL - recover.php indisponível" . PHP_EOL);
    exit(1);
}

if (preg_match('/static function \(\) use \([\s\S]*?\$codeHash,[\s\S]*?\): void \{/', $source) !== 1) {
    fwrite(STDERR, "recover_scope_regression_test: FAIL - codeHash não importado pela closure" . PHP_EOL);
    exit(1);
}

if (preg_match('/static function \(\) use \([\s\S]*?\$ticketHash,[\s\S]*?\): void \{/', $source) === 1) {
    fwrite(STDERR, "recover_scope_regression_test: FAIL - ticketHash legado ainda importado" . PHP_EOL);
    exit(1);
}

if (substr_count($source, '$codeHash') < 3) {
    fwrite(STDERR, "recover_scope_regression_test: FAIL - fluxo de codeHash incompleto" . PHP_EOL);
    exit(1);
}

echo 'recover_scope_regression_test: OK' . PHP_EOL;
