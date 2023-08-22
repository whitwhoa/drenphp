<?php

namespace Dren;

use Dren\Jobs\JobExecutionTypeInterface;
use Dren\Model\DAOs\JobDAO;
use Exception;

abstract class Job implements JobExecutionTypeInterface
{
    protected mixed $data; // decoded json, so either an array, an stdClass, or null

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

	public function run() : bool
    {
        // TODO: register a shutdown function to handle when fatal error occurs
        // This was naive, while it would be fine for the scheduled jobs, this will not work for the worker queues,
        // since they never exit. We would end up with thousands of registered functions for every successful execution
        // because the callback captures the object reference, also meaning that no instantiated job class would ever
        // be picked up by the garbage collector...0_0...
        //
        // Not quite sure how to go about this now really...great...
        register_shutdown_function(function() {

            $error = error_get_last();
            if($error !== null)
            {
                if($this->executionId !== null)
                    $this->jobDao->updateJobExecution($this->executionId, date('Y-m-d H:i:s'), 'FAILED', 'FAILED', var_export($error, true));
                else
                    Logger::write(var_export($error, true));
            }

            $this->mutex->closeLock();
            $this->mutex->deleteUnsafe();

        });

        // TODO: break this into multiple private functions and then have run() dictate which function is called
        // based on $this->isConcurrent()

        try
        {
            $this->jobLockId = $this->generateFilenameFromObject();

            if($this->mutex->idExists($this->jobLockId))
            {
                if(!$this->mutex->tryToLock($this->jobLockId))
                    return false; // unable to get lock so another process is doing something with this job, get out

                $prevId = $this->mutex->getContents();
                if($prevId != '')
                    $this->jobDao->updateJobExecution((int)$this->mutex->getContents(), date('Y-m-d H:i:s'),
                        'FAILED', 'INTERRUPTED', null);
            }
            else
            {
                $this->mutex->openLock($this->jobLockId);
            }

            // if preCondition() fails, there is no reason to run this job, it hasn't failed, it just doesn't need to
            // be run at this time. An example would be if the job is dependent on a file existing that's uploaded, but
            // it doesn't exist because some other process hasn't provided it yet, the preCondition method would
            // execute and check if it exists and only return true if it does, indicating that it's correct to proceed
            if(!$this->preCondition())
            {
                // pre-condition not met, so there's nothing to log, just close and delete the lock, and return true
                $this->mutex->closeLock();
                $this->mutex->deleteUnsafe();

                return true;
            }

            $this->executionId = $this->jobDao->createJobExecution(getmypid(), $this->jobLockId, date('Y-m-d H:i:s'), 'RUNNING');
            $this->mutex->overwriteContents($this->executionId);

            $this->logic();

            // write to database as successful, close and delete mutex
            $this->jobDao->updateJobExecution($this->executionId, date('Y-m-d H:i:s'), 'COMPLETED', 'SUCCESS', $this->successMessage);
            $this->mutex->closeLock();
            $this->mutex->deleteUnsafe();

            return true;
        }
        catch(Exception $e)
        {
            $errorMessage = $e->getMessage() . ":" . $e->getTraceAsString();

            // write to database as un-successful if we have an execution id, otherwise write to log, close and delete mutex
            if($this->executionId !== null)
                $this->jobDao->updateJobExecution($this->executionId, date('Y-m-d H:i:s'), 'FAILED','FAILED', $errorMessage);
            else
                Logger::write($errorMessage);

            $this->mutex->closeLock();
            $this->mutex->deleteUnsafe();

            return false;
        }
    }

    public function generateFilenameFromObject(): string
    {
        return str_replace('\\', '_', get_class($this));
    }


}