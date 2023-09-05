<?php
declare(strict_types=1);

namespace Dren\Jobs\ExecutionTypes;

interface JobExecutionTypeInterface
{
    public function setExecutionType(): void;
}