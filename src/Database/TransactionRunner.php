<?php

declare(strict_types=1);

namespace DaVez\Database;

use RuntimeException;
use Throwable;

final class TransactionRunner
{
    /** @var TransactionalConnection */
    private $connection;

    public function __construct(TransactionalConnection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Executa todo o callback dentro da mesma transação.
     *
     * O callback deve lançar uma exceção quando qualquer escrita esperada falhar.
     *
     * @return mixed
     */
    public function run(callable $work)
    {
        $this->connection->beginTransaction();

        try {
            $result = $work();
            $this->connection->commit();

            return $result;
        } catch (Throwable $failure) {
            try {
                $this->connection->rollback();
            } catch (Throwable $rollbackFailure) {
                throw new RuntimeException(
                    'A operação falhou e o rollback também não pôde ser confirmado.',
                    0,
                    $failure
                );
            }

            throw $failure;
        }
    }
}
