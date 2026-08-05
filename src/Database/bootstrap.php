<?php

declare(strict_types=1);

require_once __DIR__ . '/TransactionalConnection.php';
require_once __DIR__ . '/AdvisoryLock.php';
require_once __DIR__ . '/TransactionRunner.php';
require_once __DIR__ . '/LockUnavailable.php';
require_once __DIR__ . '/LockedTransactionRunner.php';
require_once __DIR__ . '/AtomicOrderAllocator.php';
require_once __DIR__ . '/MysqliTransactionConnection.php';
require_once __DIR__ . '/MysqliAdvisoryLock.php';
require_once __DIR__ . '/../Domain/LegacyIdentity.php';
require_once __DIR__ . '/../Domain/TokenCycle.php';
require_once __DIR__ . '/SettingsTokenCycle.php';
require_once __DIR__ . '/PublicIdentityStore.php';

if (!function_exists('davez_database_release_observer')) {
    function davez_database_release_observer(array $event): void
    {
        if (!function_exists('log_event')) {
            return;
        }

        $committed = ($event['committed'] ?? false) === true;
        log_event(
            $committed
                ? 'DB_LOCK_RELEASE_FAILED_COMMITTED'
                : 'DB_LOCK_RELEASE_FAILED_UNCOMMITTED'
        );
    }
}

if (!function_exists('davez_atomic_order_allocator')) {
    function davez_atomic_order_allocator(mysqli $connection): \DaVez\Database\AtomicOrderAllocator
    {
        $transactions = new \DaVez\Database\TransactionRunner(
            new \DaVez\Database\MysqliTransactionConnection($connection)
        );

        return new \DaVez\Database\AtomicOrderAllocator(
            $transactions,
            new \DaVez\Database\MysqliAdvisoryLock($connection),
            'davez_database_release_observer'
        );
    }
}

if (!function_exists('davez_locked_transaction_runner')) {
    function davez_locked_transaction_runner(mysqli $connection): \DaVez\Database\LockedTransactionRunner
    {
        $transactions = new \DaVez\Database\TransactionRunner(
            new \DaVez\Database\MysqliTransactionConnection($connection)
        );

        return new \DaVez\Database\LockedTransactionRunner(
            $transactions,
            new \DaVez\Database\MysqliAdvisoryLock($connection),
            'davez_database_release_observer'
        );
    }
}

if (!function_exists('davez_settings_token_cycle')) {
    function davez_settings_token_cycle(mysqli $connection): \DaVez\Database\SettingsTokenCycle
    {
        return new \DaVez\Database\SettingsTokenCycle($connection);
    }
}

if (!function_exists('davez_public_identity_store')) {
    function davez_public_identity_store(mysqli $connection): \DaVez\Database\PublicIdentityStore
    {
        return new \DaVez\Database\PublicIdentityStore($connection);
    }
}

if (!function_exists('davez_configure_operational_database_timezone')) {
    function davez_configure_operational_database_timezone(
        mysqli $connection,
        \DaVez\Domain\OperationalCycle $cycle,
        ?\DateTimeInterface $reference = null
    ): void {
        $offset = $cycle->utcOffset($reference);

        if (preg_match('/\A[+-](?:0[0-9]|1[0-4]):[0-5][0-9]\z/', $offset) !== 1) {
            throw new RuntimeException(
                'O fuso operacional não pôde ser convertido para o banco.'
            );
        }

        if (!$connection->query("SET time_zone = '" . $offset . "'")) {
            throw new RuntimeException(
                'Não foi possível configurar o fuso operacional no banco.'
            );
        }
    }
}
