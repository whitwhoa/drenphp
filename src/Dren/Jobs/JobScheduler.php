<?php

namespace Dren\Jobs;

use Dren\Job;
use Dren\JobExecutor;

class JobScheduler extends Job
{
    /*
     * [
     *      [
     *          ['JobName', Json encode-able array, 'UNIX CRONTAB']
     *      ], // a single job execution
     *      [
     *          ['JobName2', Json encode-able array, 'UNIX CRONTAB'],
     *          ['JobName3', Json encode-able array, 'UNIX CRONTAB']
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

        //TODO:
        // Loop through each $scheduleData
        //      check crontab with $this->jobExecutor->crontabMatchesNow($cron)
        //      if match found
        //          pass array data to $this->jobExecutor->exec($theArray)
        //              ->exec($theArray) will loop through the array and build out the cli execution string
        //              and then run the cli command

    }

    public function schedule() : void
    {
        // $this->addJob('Job1', '* * * * *')
        // $this->addJob('Job2', '* * * * *', ["somedata1" => "somedata1_value"])
        // $this->addAggregateJob([['Job3', [], '* * * * *'], ['Job4', [], '* * * * *']]);

        // TODO: have to re-work the format of aggregate jobs such that they only have one crontab entry,
        // not sure how that got past me initially

    }

    public function addJob(string $jobClassName, string $unixCronTab, mixed $data = []) : void
    {
        $this->scheduleData[] = [$jobClassName, $data, $unixCronTab];
    }

    /**
     *
     * jobClassName, Json encode-able array, unix crontab, data is not optional for aggregates, pass empty if no data
     * @param array<array{string, array, string}> $aggregateJob
     * @return void
     */
    public function addAggregateJob(array $aggregateJob) : void
    {
        $this->scheduleData[] = $aggregateJob;
    }
}