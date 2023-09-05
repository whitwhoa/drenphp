<?php
declare(strict_types=1);

namespace Dren\Jobs\ExecutionTypes;

trait ConcurrentJob
{
    public function setExecutionType(): void
    {
        $this->concurrentExecutionAllowed = true;
    }
}