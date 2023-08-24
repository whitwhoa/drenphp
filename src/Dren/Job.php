<?php

namespace Dren;

use Dren\Jobs\JobExecutionTypeInterface;
use Dren\Model\DAOs\JobDAO;
use Exception;

abstract class Job implements JobExecutionTypeInterface
{
    protected mixed $data; // decoded json

    protected bool $concurrentExecutionAllowed;

    protected ?string $successMessage;

    function __construct(mixed $data = null)
    {
        $this->data = !$data ? null : $data;
        $this->setExecutionType();
        $this->successMessage = null;
    }

    abstract public function preCondition() : bool;

	abstract public function logic() : void;

    public function isConcurrent() : bool
    {
        return $this->concurrentExecutionAllowed;
    }

    public function getSuccessMessage() : ?string
    {
        return $this->successMessage;
    }

    public function generateFilenameFromObject(): string
    {
        return str_replace('\\', '_', get_class($this));
    }

}