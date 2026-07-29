<?php

declare(strict_types=1);

namespace DaVez\Database;

interface TransactionalConnection
{
    public function beginTransaction(): void;

    public function commit(): void;

    public function rollback(): void;
}
