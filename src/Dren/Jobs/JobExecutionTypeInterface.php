<?php
declare(strict_types=1);

namespace Dren\Jobs;

interface JobExecutionTypeInterface
{
    public function setExecutionType(): void;
}