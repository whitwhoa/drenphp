<?php
declare(strict_types=1);

namespace Dren;

use Dren\Jobs\JobExecutionTypeInterface;
use Dren\DAOs\JobDAO;
use Exception;

abstract class Job implements JobExecutionTypeInterface
{
    protected mixed $data; // decoded json
    protected bool $concurrentExecutionAllowed;
    protected ?string $successMessage;
    protected bool $trackExecution;
    private JobDAO $jobDao;
    private int $queueWorkers;

    private string $jobName;
    private ?string $jobId;


    function __construct(mixed $data = null)
    {
        $this->data = !$data ? null : $data;
        $this->setExecutionType();
        $this->successMessage = null;
        $this->trackExecution = true;
        $this->jobDao = new JobDAO();
        $this->queueWorkers = App::get()->getConfig()->queue->queue_workers;
        $this->jobName = get_class($this);
        $this->jobId = null;
    }

    abstract public function preCondition() : bool;

	abstract public function logic() : void;

    public function getData() : mixed
    {
        return $this->data;
    }

    public function getJobName() : string
    {
        return $this->jobName;
    }

    public function getJobId() : string
    {
        if($this->jobId !== null)
            return $this->jobId;

        $this->jobId = str_replace('\\', '_', $this->jobName);

        if($this->concurrentExecutionAllowed)
            $this->jobId = $this->jobId . '_' . time() . random_int(0, 9999);

        return $this->jobId;
    }

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

    private function getClassNameOnly() : string
    {
        $parts = explode('\\', get_class($this));
        return end($parts);
    }

    /**
     * @throws Exception
     */
    public function queue() : ?int
    {
        $encodedData = json_encode($this->data);
        if($encodedData === false)
            throw new Exception('Unable to encode data');

        return $this->jobDao->createJobQueue($this->getClassNameOnly(),
            $encodedData, rand(1, $this->queueWorkers));
    }

}