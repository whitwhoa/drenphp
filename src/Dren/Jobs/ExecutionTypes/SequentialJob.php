<?php
declare(strict_types=1);

namespace Dren\Jobs\ExecutionTypes;

trait SequentialJob
{
    public function setExecutionType(): void
    {
        $this->concurrentExecutionAllowed = false;
    }
}