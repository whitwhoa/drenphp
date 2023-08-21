<?php

namespace Dren\Jobs;

trait SequentialJob
{
    public function setExecutionType(): void
    {
        $this->concurrentExecutionAllowed = false;
    }
}