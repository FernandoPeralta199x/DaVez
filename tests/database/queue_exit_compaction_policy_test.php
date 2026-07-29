<?php

declare(strict_types=1);

$sourcePath = __DIR__ . '/../../DaVez/sair.php';
$source = file_get_contents($sourcePath);

if ($source === false) {
    fwrite(
        STDERR,
        'queue_exit_compaction_policy_test: FAIL - endpoint ausente.'
            . PHP_EOL
    );
    exit(1);
}

$requirements = [
    "davez_locked_transaction_runner"
        => 'a saída não usa o lock compartilhado',
    "status='em_entrega'"
        => 'o item não é marcado como em entrega',
    "status='na_fila'"
        => 'a compactação não limita os itens restantes',
    'ORDER BY ordem ASC, id ASC'
        => 'a compactação não possui desempate determinístico',
    'FOR UPDATE'
        => 'os itens restantes não são bloqueados',
    'QueueReorder::positions'
        => 'as posições contíguas 1..N não usam o helper testado',
    'A sequência final da fila é inválida.'
        => 'a sequência compactada não é verificada',
];

foreach ($requirements as $needle => $message) {
    if (strpos($source, $needle) === false) {
        fwrite(
            STDERR,
            "queue_exit_compaction_policy_test: FAIL - {$message}."
                . PHP_EOL
        );
        exit(1);
    }
}

echo 'queue_exit_compaction_policy_test: OK' . PHP_EOL;
