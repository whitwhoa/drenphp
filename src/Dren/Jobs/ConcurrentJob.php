<?php

namespace Dren\Jobs;

trait ConcurrentJob
{
    public function setExecutionType(): void
    {
        $this->concurrentExecutionAllowed = true;
    }
}