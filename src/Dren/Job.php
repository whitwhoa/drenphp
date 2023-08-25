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
    protected bool $trackExecution;
    private JobDAO $jobDao;
    private int $queueWorkers;
    function __construct(mixed $data = null)
    {
        $this->data = !$data ? null : $data;
        $this->setExecutionType();
        $this->successMessage = null;
        $this->trackExecution = true;
        $this->jobDao = new JobDAO();
        $this->queueWorkers = App::get()->getConfig()->queue->queue_workers;
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

    public function shouldTrackExecution() : bool
    {
        return $this->trackExecution;
    }

    public function generateFilenameFromObject() : string
    {
        return str_replace('\\', '_', get_class($this));
    }

    private function getClassNameOnly() : string
    {
        $parts = explode('\\', get_class($this));
        return end($parts);
    }

    public function queue() : ?int
    {
        return $this->jobDao->createJobQueue($this->getClassNameOnly(),
            json_encode($this->data), rand(1, $this->queueWorkers));
    }

}