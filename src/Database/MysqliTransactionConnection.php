<?php

declare(strict_types=1);

namespace DaVez\Database;

use mysqli;
use RuntimeException;

final class MysqliTransactionConnection implements TransactionalConnection
{
    /** @var mysqli */
    private $connection;

    public function __construct(mysqli $connection)
    {
        $this->connection = $connection;
    }

    public function beginTransaction(): void
    {
        if (!$this->connection->begin_transaction()) {
            throw new RuntimeException('Não foi possível iniciar a transação.');
        }
    }

    public function commit(): void
    {
        if (!$this->connection->commit()) {
            throw new RuntimeException('Não foi possível confirmar a transação.');
        }
    }

    public function rollback(): void
    {
        if (!$this->connection->rollback()) {
            throw new RuntimeException('Não foi possível reverter a transação.');
        }
    }
}
