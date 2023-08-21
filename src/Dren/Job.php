<?php

namespace Dren;

use Dren\Jobs\JobExecutionTypeInterface;
use Dren\Model\DAOs\JobDAO;
use Exception;

abstract class Job implements JobExecutionTypeInterface
{
    protected mixed $data; // decoded json, so either an array, an stdClass, or null
    protected bool $concurrentExecutionAllowed;
    private LockableDataStore $mutex;
    private JobDAO $jobDao;
    protected ?string $successMessage;
    private ?string $jobLockId;

    function __construct(mixed $data = null)
    {
        $this->data = !$data ? null : $data;
        $this->setExecutionType();

        if(App::get()->getConfig()->jobs_lockable_datastore_type === 'file')
            $this->mutex = new FileLockableDataStore(App::get()->getPrivateDir() . '/storage/locks/jobs');

        $this->jobDao = new JobDAO();
        $this->successMessage = null;
        $this->jobLockId = null;
    }

    abstract public function preCondition() : bool;

	abstract public function logic() : void;

    public function isConcurrent() : bool
    {
        return $this->concurrentExecutionAllowed;
    }

	public function run() : bool
    {
        // TODO: register a shutdown function to handle when fatal error occurs

        // TODO: break this into multiple private functions and then have run() dictate which function is called
        // based on $this->isConcurrent()

        try
        {
            $this->jobLockId = $this->generateFilenameFromObject();

            if($this->mutex->idExists($this->jobLockId))
            {
                if(!$this->mutex->tryToLock($this->jobLockId))
                    return false; // unable to get lock so another process is doing something with this job, get out

                $this->jobDao->updateJobExecution((int)$this->mutex->getContents(), date('Y-m-d H:i:s'), 'FAILED', 'INTERRUPTED');
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


            $this->logic();

            // TODO: close and delete mutex, write to database as successful

        }
        catch(Exception $e)
        {
            // TODO: close and delete mutex, write to database as un-successful

            Logger::write($e->getMessage() . ":" . $e->getTraceAsString());
            return false;
        }

        return true;
    }

    private function generateFilenameFromObject(): string
    {
        return str_replace('\\', '_', get_class($this));
    }


}