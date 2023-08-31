<?php
declare(strict_types=1);

namespace Dren;

use Dren\DAOs\JobDAO;
use Exception;

class JobExecutor
{
    private LockableDataStore $mutex;
    private JobDAO $jobDao;
    private ?Job $job;
    private ?int $executionId;

    public function __construct()
    {
        if(App::get()->getConfig()->jobs_lockable_datastore_type === 'file')
            $this->mutex = new FileLockableDataStore(App::get()->getPrivateDir() . '/storage/system/locks/jobs');

        $this->jobDao = new JobDAO();
        $this->job = null;
        $this->executionId = null;

        register_shutdown_function(function(){

            $error = error_get_last();
            if ($error !== null)
            {
                $this->handleJobFailure(var_export($error, true));
                exit(1);
            }

        });

    }

    public function verifyClassName(string $name) : bool|string
    {
        $className = '';
        if(class_exists("App\\Jobs\\" . $name))
            $className = "App\\Jobs\\" . $name;
        elseif(class_exists("Dren\\Jobs\\" . $name))
            $className = "Dren\\Jobs\\" . $name;

        if($className === '')
        {
            $message = "Provided classname: " . $name . " does not exist";
            Logger::error($message);
            echo $message . "\n";
            return false;
        }

        return $className;
    }

    public function verifyArgumentCount(int $argCount) : bool
    {
        if($argCount % 2 == 0)
        {
            $message = "When calling multiple jobs with runjob command, total number of arguments must be odd";
            Logger::error($message);
            echo $message . "\n";
            return false;
        }

        return true;
    }

    /**
     * @throws Exception
     */
    public function handleJobFailure(string $errorMessage) : void
    {
        if($this->executionId !== null)
            $this->jobDao->updateJobExecution($this->executionId, date('Y-m-d H:i:s'), 'FAILED','FAILED', $errorMessage);
        else
            Logger::error($errorMessage);

        $this->mutex->closeLock();
        $this->mutex->deleteUnsafe();
    }

    /**
     * @throws Exception
     */
    public function runConcurrent(Job $j) : bool
    {
        $this->job = $j;

        $this->mutex->openLock($this->job->getJobId());

        return $this->execJob();
    }

    /**
     * @throws Exception
     */
    function runSequential(Job $j) : bool
    {
        $this->job = $j;

        if($this->mutex->idExists($this->job->getJobId()))
        {
            if(!$this->mutex->tryToLock($this->job->getJobId()))
            {
                $message = "Sequential Job attempted to run while another instance of job was currently running: " . $this->job->getJobName();
                Logger::warning($message);
                echo $message . "\n";
                return false; // unable to get lock so another process is doing something with this job, get out
            }

            $prevId = $this->mutex->getContents();
            if($prevId != '')
                $this->jobDao->updateJobExecution((int)$prevId, date('Y-m-d H:i:s'),'FAILED', 'INTERRUPTED', null);
        }
        else
        {
            $this->mutex->openLock($this->job->getJobId());
        }

        return $this->execJob();
    }

    /**
     * @throws Exception
     */
    private function execJob() : bool
    {
        if(!$this->job->preCondition())
        {
            $this->mutex->closeLock();
            $this->mutex->deleteUnsafe();

            return false;
        }

        $this->executionId = null;
        if($this->job->shouldTrackExecution())
        {
            $this->executionId = $this->jobDao->createJobExecution(
                getmypid(),
                $this->job->getJobName(),
                date('Y-m-d H:i:s'),
                'RUNNING',
                ($this->job->getData() ? json_encode($this->job->getData()) : null)
            );
            $this->mutex->overwriteContents((string)$this->executionId);
        }
        else
        {
            $this->mutex->overwriteContents('');
        }

        $this->job->logic();

        if($this->job->shouldTrackExecution())
            $this->jobDao->updateJobExecution($this->executionId, date('Y-m-d H:i:s'), 'COMPLETED', 'SUCCESS', $this->job->getSuccessMessage());

        $this->mutex->closeLock();
        $this->mutex->deleteUnsafe();

        return true;
    }

}