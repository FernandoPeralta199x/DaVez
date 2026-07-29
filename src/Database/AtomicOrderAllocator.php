<?php

declare(strict_types=1);

namespace DaVez\Database;

use RuntimeException;

final class AtomicOrderAllocator
{
    public const RELEASE_FAILURE_EVENT = LockedTransactionRunner::RELEASE_FAILURE_EVENT;

    /** @var LockedTransactionRunner */
    private $lockedTransactions;

    public function __construct(
        TransactionRunner $transactions,
        AdvisoryLock $lock,
        ?callable $onReleaseFailure = null
    ) {
        $this->lockedTransactions = new LockedTransactionRunner(
            $transactions,
            $lock,
            $onReleaseFailure
        );
    }

    /**
     * Reserva e persiste a próxima posição no mesmo lock e na mesma transação.
     *
     * $readMaximum e $persist devem usar a mesma conexão de banco que sustenta
     * TransactionRunner e AdvisoryLock. $persist deve lançar em qualquer falha.
     *
     * @return int posição persistida
     */
    public function allocateAndPersist(
        string $scope,
        callable $readMaximum,
        callable $persist,
        int $timeoutSeconds = 3
    ): int {
        return $this->lockedTransactions->run(
            $scope,
                static function () use ($readMaximum, $persist): int {
                    $maximum = $readMaximum();

                    if (
                        !is_int($maximum)
                        && !(is_string($maximum) && ctype_digit($maximum))
                    ) {
                        throw new RuntimeException('A maior posição retornada pelo banco é inválida.');
                    }

                    $maximum = (int) $maximum;

                    if ($maximum < 0 || $maximum === PHP_INT_MAX) {
                        throw new RuntimeException('A maior posição retornada está fora do intervalo permitido.');
                    }

                    $next = $maximum + 1;
                    $persist($next);

                    return $next;
                },
            $timeoutSeconds
        );
    }

    public static function lockName(string $scope): string
    {
        return LockedTransactionRunner::lockName($scope);
    }
}
