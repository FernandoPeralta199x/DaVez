<?php

declare(strict_types=1);

namespace DaVez\Database;

interface AdvisoryLock
{
    public function acquire(string $name, int $timeoutSeconds): bool;

    public function release(string $name): void;
}
