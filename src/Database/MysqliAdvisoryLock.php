<?php

declare(strict_types=1);

namespace DaVez\Database;

use mysqli;
use RuntimeException;

final class MysqliAdvisoryLock implements AdvisoryLock
{
    /** @var mysqli */
    private $connection;

    public function __construct(mysqli $connection)
    {
        $this->connection = $connection;
    }

    public function acquire(string $name, int $timeoutSeconds): bool
    {
        $statement = $this->connection->prepare(
            'SELECT GET_LOCK(?, ?) AS acquired'
        );

        if (!$statement) {
            throw new RuntimeException('Não foi possível preparar a aquisição do lock.');
        }

        try {
            $statement->bind_param('si', $name, $timeoutSeconds);

            if (!$statement->execute()) {
                throw new RuntimeException('Não foi possível executar a aquisição do lock.');
            }

            $acquired = null;
            $statement->bind_result($acquired);
            $statement->fetch();

            return (int) $acquired === 1;
        } finally {
            $statement->close();
        }
    }

    public function release(string $name): void
    {
        $statement = $this->connection->prepare(
            'SELECT RELEASE_LOCK(?) AS released'
        );

        if (!$statement) {
            throw new RuntimeException('Não foi possível preparar a liberação do lock.');
        }

        try {
            $statement->bind_param('s', $name);

            if (!$statement->execute()) {
                throw new RuntimeException('Não foi possível executar a liberação do lock.');
            }

            $released = null;
            $statement->bind_result($released);
            $statement->fetch();

            if ((int) $released !== 1) {
                throw new RuntimeException('O lock da fila não foi liberado pela sessão atual.');
            }
        } finally {
            $statement->close();
        }
    }
}
