<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Domain/QueueStateChanged.php';
require_once __DIR__ . '/../../src/Domain/QueueReorder.php';

use DaVez\Domain\QueueReorder;
use DaVez\Domain\QueueStateChanged;

function assert_queue_reorder(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "queue_reorder_test: FAIL - {$message}" . PHP_EOL);
        exit(1);
    }
}

$ids = QueueReorder::normalize(['3', 1, 2]);
assert_queue_reorder($ids === [3, 1, 2], 'A ordem enviada deve ser preservada.');
assert_queue_reorder(
    QueueReorder::positions($ids) === [3 => 1, 1 => 2, 2 => 3],
    'As posições devem ser contíguas de 1 a N.'
);
QueueReorder::assertExactSet($ids, [1, 2, 3]);

$changed = false;
try {
    QueueReorder::assertExactSet($ids, [1, 2, 4]);
} catch (QueueStateChanged $exception) {
    $changed = true;
}
assert_queue_reorder($changed, 'Conjunto divergente deveria gerar conflito.');

foreach ([[1, 1], [], [0, 1]] as $invalid) {
    $rejected = false;
    try {
        QueueReorder::normalize($invalid);
    } catch (InvalidArgumentException $exception) {
        $rejected = true;
    }
    assert_queue_reorder($rejected, 'Payload inválido deveria ser rejeitado.');
}

echo 'queue_reorder_test: OK' . PHP_EOL;
