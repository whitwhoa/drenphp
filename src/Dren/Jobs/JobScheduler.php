<?php

namespace Dren\Jobs;

use Dren\Job;
use Dren\JobExecutor;

abstract class JobScheduler extends Job
{
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

    function __construct(mixed $data = null)
    {
        parent::__construct($data);

        $this->scheduleData = [];
        $this->jobExecutor = new JobExecutor();
    }

    public function preCondition(): bool
    {
        return true;
    }

    public function logic(): void
    {
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
}