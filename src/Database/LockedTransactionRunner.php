<?php

declare(strict_types=1);

namespace DaVez\Database;

use InvalidArgumentException;
use Throwable;

final class LockedTransactionRunner
{
    public const RELEASE_FAILURE_EVENT = 'advisory_lock_release_failed';

    private const LOCK_PREFIX = 'davez:queue:';
    private const MAX_LOCK_TIMEOUT_SECONDS = 30;

    /** @var TransactionRunner */
    private $transactions;

    /** @var AdvisoryLock */
    private $lock;

    /** @var callable|null */
    private $onReleaseFailure;

    public function __construct(
        TransactionRunner $transactions,
        AdvisoryLock $lock,
        ?callable $onReleaseFailure = null
    ) {
        $this->transactions = $transactions;
        $this->lock = $lock;
        $this->onReleaseFailure = $onReleaseFailure;
    }

    /**
     * @return mixed
     */
    public function run(
        string $scope,
        callable $work,
        int $timeoutSeconds = 3
    ) {
        $scope = trim($scope);

        if ($scope === '') {
            throw new InvalidArgumentException('O escopo do lock não pode ser vazio.');
        }

        if ($timeoutSeconds < 0 || $timeoutSeconds > self::MAX_LOCK_TIMEOUT_SECONDS) {
            throw new InvalidArgumentException('O timeout do lock deve estar entre 0 e 30 segundos.');
        }

        $lockName = self::lockName($scope);

        if (!$this->lock->acquire($lockName, $timeoutSeconds)) {
            throw new LockUnavailable('Não foi possível obter o lock exclusivo da fila.');
        }

        $committed = false;
        $result = null;
        $operationFailure = null;

        try {
            $result = $this->transactions->run($work);
            $committed = true;
        } catch (Throwable $failure) {
            $operationFailure = $failure;
        }

        try {
            $this->lock->release($lockName);
        } catch (Throwable $releaseFailure) {
            $this->reportReleaseFailure($releaseFailure, $committed);
        }

        if ($operationFailure !== null) {
            throw $operationFailure;
        }

        return $result;
    }

    public static function lockName(string $scope): string
    {
        $scope = trim($scope);

        if ($scope === '') {
            throw new InvalidArgumentException('O escopo do lock não pode ser vazio.');
        }

        return self::LOCK_PREFIX . substr(hash('sha256', $scope), 0, 48);
    }

    private function reportReleaseFailure(Throwable $failure, bool $committed): void
    {
        if ($this->onReleaseFailure === null) {
            return;
        }

        try {
            call_user_func($this->onReleaseFailure, [
                'event' => self::RELEASE_FAILURE_EVENT,
                'committed' => $committed,
                'exception_class' => get_class($failure),
            ]);
        } catch (Throwable $observerFailure) {
            // Observabilidade nunca pode alterar o resultado da operação.
        }
    }
}
