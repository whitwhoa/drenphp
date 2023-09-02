<?php
declare(strict_types=1);

namespace Dren\Jobs;

use Dren\App;
use Dren\AppConfig;
use Dren\FileLockableDataStore;
use Dren\Job;
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
    /**
     * @var array<array{
     *     0: string,
     *     1: array<array{
     *         0: string,
     *         1: array<mixed>
     *     }>
     * }>
     */
    private array $scheduleData;
    private string $privateDir;
    private LockableDataStore $lockableDataStore;
    private JobDAO $jobDao;
    private AppConfig $appConfig;

    function __construct(mixed $data = null)
    {
        parent::__construct($data);

        $this->appConfig = App::get()->getConfig();
        $this->privateDir = App::get()->getPrivateDir();
        $this->scheduleData = [];

        if($this->appConfig->jobs_lockable_datastore_type === 'file')
            $this->lockableDataStore = new FileLockableDataStore($this->privateDir . '/storage/system/locks/jobs');

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

        if($this->appConfig->queue->use_worker_queue)
            $this->addJob('* * * * *', 'WorkerProcessManager');

        $this->schedule();

        foreach($this->scheduleData as $sd)
        {
            if(!$this->crontabMatchesNow($sd[0]))
                continue;

            $this->exec($sd[1]);
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
     * @param array<array{string, array{string, array<mixed>}}> $aggregateJob
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

    /**
     * @throws Exception
     */
    private function encodeForCLI(mixed $data) : string
    {
        $json = json_encode($data);
        if($json === false)
            throw new Exception('Unable to encode data');

        return escapeshellarg($json);
    }

    /**
     * Execute a shell command in the background.
     *
     * @param string $command     The command to execute.
     * @param string $outputFile  The file where the command output should be directed. Defaults to /dev/null.
     * @return void
     */
    private function runInBackground(string $command, string $outputFile = '/dev/null'): void
    {
        $formattedCommand = sprintf('%s > %s 2>&1 & echo $!', $command, $outputFile);
        shell_exec($formattedCommand);
    }

    /**
     * TODO: this needs to take into account the fact that (while not likely to happen for MY use cases, it could)
     * command line arguments have a max size, often 2mb. In order to work around this, we could add functionality
     * to check if the size is reaching that limit, and if so, use temp files and xargs
     *
     * @param array<array{string, array<mixed>}> $jobs
     * @return void
     */
    private function exec(array $jobs) : void
    {
        if(count($jobs) === 0)
            return;

        $command = "php " . $this->privateDir . "/runjob";

        foreach($jobs as $j)
            $command .= " " . $j[0] . " " . $this->encodeForCLI($j[1]);

        $this->runInBackground($command);
    }

    /* Function for parsing crontab entries and determining if they match the
     * execution time of this script
    *************************************************************************/
    private function crontabMatchesNow(string $cron) : bool
    {
        $cronParts = explode(' ', $cron);

        if(count($cronParts) != 5)
            return false;

        list($min, $hour, $day, $mon, $week) = $cronParts;

        $to_check = ['min' => 'i', 'hour' => 'G', 'day' => 'j', 'mon' => 'n', 'week' => 'w'];
        $ranges = ['min' => '0-59', 'hour' => '0-23', 'day' => '1-31', 'mon' => '1-12', 'week' => '0-6'];

        foreach($to_check as $part => $c)
        {
            $val = ${$part};  // Using {} for clarity

            if ($val == '*')
                continue; // Wildcard matches everything, so continue to next loop iteration

            $values = [];

            /*For patters like:
                0-23/2
            */
            if(str_contains($val, '/'))
            {
                list($range, $steps) = explode('/', $val);
                $steps = (int) $steps;

                if($range == '*')
                    $range = $ranges[$part];

                list($start, $stop) = explode('-', $range);
                $start = (int) $start;
                $stop = (int) $stop;

                for($i = $start; $i <= $stop; $i += $steps)
                    $values[] = $i;
            }
            /*For patters like:
                2
                2,5,8
                2-23
            */
            else
            {
                $k = explode(',', $val);

                foreach($k as $v)
                {
                    if(str_contains($v, '-'))
                    {
                        list($start, $stop) = explode('-', $v);
                        $start = (int) $start;
                        $stop = (int) $stop;

                        for($i = $start; $i <= $stop; $i++)
                            $values[] = $i;
                    }
                    else
                        $values[] = (int) $v;
                }
            }

            if (!in_array((int) date($c), $values))
                return false;
        }

        return true;
    }

}