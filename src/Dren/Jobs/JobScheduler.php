<?php

namespace Dren\Jobs;

use Dren\App;
use Dren\FileLockableDataStore;
use Dren\Job;
use Dren\JobExecutor;
use Dren\LockableDataStore;
use Dren\DAOs\JobDAO;
use Exception;

abstract class JobScheduler extends Job
{
    use SequentialJob;

    /*
     * [
     *      [
     *          'UNIX CRONTAB',
     *          [
     *              ['JobName', Json encode-able array]
     *          ]
     *      ], // a single job execution
     *      [
     *          'UNIX CRONTAB',
     *          [
     *              ['JobName2', Json encode-able array],
     *              ['JobName3', Json encode-able array],
     *              ['JobName4', Json encode-able array]
     *          ]
     *      ] // an aggregate job execution, each subsequent job depends on the successful execution of the previous
     * ]
     */
    private array $scheduleData;
    private JobExecutor $jobExecutor;

    private LockableDataStore $lockableDataStore;
    private JobDAO $jobDao;

    function __construct(mixed $data = null)
    {
        parent::__construct($data);

        $this->scheduleData = [];
        $this->jobExecutor = new JobExecutor();

        if(App::get()->getConfig()->jobs_lockable_datastore_type === 'file')
            $this->lockableDataStore = new FileLockableDataStore(App::get()->getPrivateDir() . '/storage/system/locks/jobs');

        $this->jobDao = new JobDAO();

        $this->trackExecution = false;
    }

    public function preCondition(): bool
    {
        return true;
    }

    /**
     * @throws Exception
     */
    public function logic(): void
    {
        $this->cleanInterrupted();

        $this->schedule();

        foreach($this->scheduleData as $sd)
        {
            if(!$this->jobExecutor->crontabMatchesNow($sd[0]))
                continue;

            $this->jobExecutor->exec($sd[1]);
        }
    }

    public abstract function schedule() : void;

    public function addJob(string $unixCronTab, string $jobClassName, mixed $data = []) : void
    {
        $this->scheduleData[] = [
            $unixCronTab,
            [
                [$jobClassName, $data]
            ]
        ];
    }

    /**
     *
     * jobClassName, Json encode-able array, unix crontab, data is not optional for aggregates, pass empty if no data
     * @param string $unixCronTab
     * @param array<array{string, array}> $aggregateJob
     * @return void
     */
    public function addAggregateJob(string $unixCronTab, array $aggregateJob) : void
    {
        $this->scheduleData[] = [
            $unixCronTab,
            $aggregateJob
        ];
    }

    /**
     * @throws Exception
     */
    private function cleanInterrupted() : void
    {
        $lockFiles = $this->lockableDataStore->getAllElementsInContainer();

        foreach($lockFiles as $fn)
        {
            if(!$this->lockableDataStore->idLocked($fn))
            {
                // if we're able to acquire a lock, then something happened to the executing process before it could
                // successfully remove the file, thus we need to update the execution id's database record to indicate
                // that this execution of the job was interrupted. There's a theoretical race-condition here since there's
                // an ever so slight chance that we could make it here in between the job releasing the lock and deleting
                // the file, but it's so small, I'm going to ignore it.
                $this->jobDao->updateJobExecution((int)$this->lockableDataStore->getContentsUnsafe($fn),
                    date('Y-m-d H:i:s'),'FAILED', 'INTERRUPTED', null);

                $this->lockableDataStore->deleteUnsafeById($fn);
            }
        }
    }
}