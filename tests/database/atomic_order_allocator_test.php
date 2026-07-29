<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Database/TransactionalConnection.php';
require_once __DIR__ . '/../../src/Database/AdvisoryLock.php';
require_once __DIR__ . '/../../src/Database/TransactionRunner.php';
require_once __DIR__ . '/../../src/Database/LockUnavailable.php';
require_once __DIR__ . '/../../src/Database/LockedTransactionRunner.php';
require_once __DIR__ . '/../../src/Database/AtomicOrderAllocator.php';

use DaVez\Database\AdvisoryLock;
use DaVez\Database\AtomicOrderAllocator;
use DaVez\Database\TransactionalConnection;
use DaVez\Database\TransactionRunner;

function fail_atomic_order_test(string $message): void
{
    fwrite(STDERR, "atomic_order_allocator_test: FAIL - {$message}" . PHP_EOL);
    exit(1);
}

function assert_atomic_order(bool $condition, string $message): void
{
    if (!$condition) {
        fail_atomic_order_test($message);
    }
}

final class FakeTransactionalConnection implements TransactionalConnection
{
    /** @var array<int, string> */
    public $events;

    /** @var string|null */
    public $commitFailureMessage;

    public function __construct(array &$events)
    {
        $this->events = &$events;
        $this->commitFailureMessage = null;
    }

    public function beginTransaction(): void
    {
        $this->events[] = 'begin';
    }

    public function commit(): void
    {
        $this->events[] = 'commit';

        if ($this->commitFailureMessage !== null) {
            throw new RuntimeException($this->commitFailureMessage);
        }
    }

    public function rollback(): void
    {
        $this->events[] = 'rollback';
    }
}

final class FakeAdvisoryLock implements AdvisoryLock
{
    /** @var array<int, string> */
    public $events;

    /** @var bool */
    public $willAcquire = true;

    /** @var string|null */
    public $releaseFailureMessage;

    public function __construct(array &$events)
    {
        $this->events = &$events;
        $this->releaseFailureMessage = null;
    }

    public function acquire(string $name, int $timeoutSeconds): bool
    {
        $this->events[] = "acquire:{$name}:{$timeoutSeconds}";

        return $this->willAcquire;
    }

    public function release(string $name): void
    {
        $this->events[] = "release:{$name}";

        if ($this->releaseFailureMessage !== null) {
            throw new RuntimeException($this->releaseFailureMessage);
        }
    }
}

$events = [];
$connection = new FakeTransactionalConnection($events);
$lock = new FakeAdvisoryLock($events);
$allocator = new AtomicOrderAllocator(new TransactionRunner($connection), $lock);
$scope = 'checkins:2026-07-29';
$lockName = AtomicOrderAllocator::lockName($scope);

$next = $allocator->allocateAndPersist(
    $scope,
    static function () use (&$events): int {
        $events[] = 'read';
        return 7;
    },
    static function (int $order) use (&$events): void {
        $events[] = "persist:{$order}";
    }
);

assert_atomic_order($next === 8, 'A próxima posição deveria ser 8.');
assert_atomic_order(
    $events === [
        "acquire:{$lockName}:3",
        'begin',
        'read',
        'persist:8',
        'commit',
        "release:{$lockName}",
    ],
    'Lock, leitura, persistência, commit e liberação ocorreram fora da ordem segura.'
);
assert_atomic_order(
    strlen($lockName) <= 64,
    'O nome do advisory lock ultrapassa o limite do MySQL.'
);

$events = [];
$connection = new FakeTransactionalConnection($events);
$lock = new FakeAdvisoryLock($events);
$allocator = new AtomicOrderAllocator(new TransactionRunner($connection), $lock);
$failurePropagated = false;

try {
    $allocator->allocateAndPersist(
        'fila_da_vez:2026-07-29',
        static function () use (&$events): int {
            $events[] = 'read';
            return 3;
        },
        static function (int $order) use (&$events): void {
            $events[] = "persist:{$order}";
            throw new RuntimeException('falha simulada');
        }
    );
} catch (RuntimeException $exception) {
    $failurePropagated = $exception->getMessage() === 'falha simulada';
}

assert_atomic_order($failurePropagated, 'A falha de persistência deveria ser propagada.');
assert_atomic_order(
    in_array('rollback', $events, true),
    'Uma falha de persistência deveria executar rollback.'
);
assert_atomic_order(
    strncmp((string) end($events), 'release:', 8) === 0,
    'O advisory lock deveria ser liberado mesmo após rollback.'
);
assert_atomic_order(
    !in_array('commit', $events, true),
    'Uma transação com falha não pode executar commit.'
);

$events = [];
$connection = new FakeTransactionalConnection($events);
$lock = new FakeAdvisoryLock($events);
$lock->willAcquire = false;
$allocator = new AtomicOrderAllocator(new TransactionRunner($connection), $lock);
$lockFailurePropagated = false;

try {
    $allocator->allocateAndPersist(
        'checkins:2026-07-30',
        static function (): int {
            return 0;
        },
        static function (int $order): void {
        }
    );
} catch (RuntimeException $exception) {
    $lockFailurePropagated = true;
}

assert_atomic_order($lockFailurePropagated, 'A indisponibilidade do lock deveria abortar a operação.');
assert_atomic_order(
    count($events) === 1 && strncmp($events[0], 'acquire:', 8) === 0,
    'Sem lock não deve existir transação, leitura, escrita ou liberação.'
);

$events = [];
$connection = new FakeTransactionalConnection($events);
$lock = new FakeAdvisoryLock($events);
$allocator = new AtomicOrderAllocator(new TransactionRunner($connection), $lock);
$invalidMaximumRejected = false;

try {
    $allocator->allocateAndPersist(
        'checkins:2026-07-31',
        static function (): string {
            return '-1';
        },
        static function (int $order): void {
        }
    );
} catch (RuntimeException $exception) {
    $invalidMaximumRejected = true;
}

assert_atomic_order($invalidMaximumRejected, 'Uma posição máxima inválida deveria ser rejeitada.');
assert_atomic_order(
    in_array('rollback', $events, true),
    'A rejeição da posição máxima deveria executar rollback.'
);

$events = [];
$observedReleaseFailures = [];
$connection = new FakeTransactionalConnection($events);
$lock = new FakeAdvisoryLock($events);
$lock->releaseFailureMessage = 'falha de release após commit';
$allocator = new AtomicOrderAllocator(
    new TransactionRunner($connection),
    $lock,
    static function (array $event) use (&$observedReleaseFailures): void {
        $observedReleaseFailures[] = $event;
        throw new RuntimeException('falha simulada do observador');
    }
);

$nextAfterReleaseFailure = $allocator->allocateAndPersist(
    'checkins:2026-08-01',
    static function () use (&$events): int {
        $events[] = 'read';
        return 4;
    },
    static function (int $order) use (&$events): void {
        $events[] = "persist:{$order}";
    }
);

assert_atomic_order(
    $nextAfterReleaseFailure === 5,
    'Falha de release após commit não pode transformar a escrita confirmada em falha.'
);
assert_atomic_order(
    in_array('commit', $events, true) && !in_array('rollback', $events, true),
    'A escrita confirmada deve permanecer confirmada após falha de release.'
);
assert_atomic_order(
    strncmp((string) end($events), 'release:', 8) === 0,
    'A liberação do lock deve ser tentada após o commit.'
);
assert_atomic_order(
    count($observedReleaseFailures) === 1,
    'A falha pós-commit deve produzir exatamente um evento de observabilidade.'
);
assert_atomic_order(
    $observedReleaseFailures[0] === [
        'event' => AtomicOrderAllocator::RELEASE_FAILURE_EVENT,
        'committed' => true,
        'exception_class' => RuntimeException::class,
    ],
    'O evento pós-commit deve ser sanitizado e indicar commit confirmado.'
);

$events = [];
$observedReleaseFailures = [];
$connection = new FakeTransactionalConnection($events);
$lock = new FakeAdvisoryLock($events);
$lock->releaseFailureMessage = 'falha de release durante rollback';
$allocator = new AtomicOrderAllocator(
    new TransactionRunner($connection),
    $lock,
    static function (array $event) use (&$observedReleaseFailures): void {
        $observedReleaseFailures[] = $event;
        throw new RuntimeException('falha simulada do observador');
    }
);
$originalFailurePreserved = false;

try {
    $allocator->allocateAndPersist(
        'fila_da_vez:2026-08-01',
        static function () use (&$events): int {
            $events[] = 'read';
            return 2;
        },
        static function (int $order) use (&$events): void {
            $events[] = "persist:{$order}";
            throw new RuntimeException('falha original de persistência');
        }
    );
} catch (RuntimeException $exception) {
    $originalFailurePreserved = $exception->getMessage() === 'falha original de persistência';
}

assert_atomic_order(
    $originalFailurePreserved,
    'Falha de release ou do observador não pode mascarar a falha original.'
);
assert_atomic_order(
    in_array('rollback', $events, true) && !in_array('commit', $events, true),
    'A falha anterior ao commit deve executar rollback e nunca confirmar a transação.'
);
assert_atomic_order(
    strncmp((string) end($events), 'release:', 8) === 0,
    'A liberação do lock deve ser tentada mesmo sem commit.'
);
assert_atomic_order(
    $observedReleaseFailures[0] === [
        'event' => AtomicOrderAllocator::RELEASE_FAILURE_EVENT,
        'committed' => false,
        'exception_class' => RuntimeException::class,
    ],
    'O evento anterior ao commit deve ser sanitizado e indicar operação não confirmada.'
);

$events = [];
$observedReleaseFailures = [];
$connection = new FakeTransactionalConnection($events);
$connection->commitFailureMessage = 'falha original de commit';
$lock = new FakeAdvisoryLock($events);
$lock->releaseFailureMessage = 'falha de release após commit não confirmado';
$allocator = new AtomicOrderAllocator(
    new TransactionRunner($connection),
    $lock,
    static function (array $event) use (&$observedReleaseFailures): void {
        $observedReleaseFailures[] = $event;
    }
);
$commitFailurePreserved = false;

try {
    $allocator->allocateAndPersist(
        'checkins:2026-08-02',
        static function () use (&$events): int {
            $events[] = 'read';
            return 9;
        },
        static function (int $order) use (&$events): void {
            $events[] = "persist:{$order}";
        }
    );
} catch (RuntimeException $exception) {
    $commitFailurePreserved = $exception->getMessage() === 'falha original de commit';
}

assert_atomic_order(
    $commitFailurePreserved,
    'Falha de release não pode mascarar uma falha de commit.'
);
assert_atomic_order(
    in_array('commit', $events, true) && in_array('rollback', $events, true),
    'Falha de commit deve acionar rollback antes da tentativa de release.'
);
assert_atomic_order(
    $observedReleaseFailures[0]['committed'] === false,
    'Commit com exceção não pode ser reportado como confirmado.'
);

echo 'atomic_order_allocator_test: OK' . PHP_EOL;
