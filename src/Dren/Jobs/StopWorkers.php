<?php
declare(strict_types=1);

namespace Dren\Jobs;

use Dren\Configs\AppConfig;
use Dren\FileLockableDataStore;
use Dren\Job;
use Dren\Jobs\ExecutionTypes\SequentialJob;
use Dren\App;
use Dren\LockableDataStore;
use Exception;

class StopWorkers extends Job
{
    use SequentialJob;

    private AppConfig $appConfig;
    private LockableDataStore $lockableDataStore;
    private string $dataStoreId;


    function __construct(mixed $data = null)
    {
        parent::__construct($data);
        //$this->trackExecution = false;

        $this->appConfig = App::get()->getConfig();

        //TODO: re-implement this conditional when necessary
        // if($this->queueConfig->lockable_datastore_type === 'file')
        $this->dataStoreId = 'data.json';
        $this->lockableDataStore = new FileLockableDataStore(App::get()->getPrivateDir() . '/storage/system/queue');

        if(!$this->lockableDataStore->idExists($this->dataStoreId))
            $this->lockableDataStore->overwriteContentsUnsafe($this->dataStoreId, "[]");

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
        // get process ids of running processes
        $data = json_decode($this->lockableDataStore->getContentsUnsafe($this->dataStoreId));


        // touch a startworkers file in app system storage. Each job process will check for the existence of this file
        // at the beginning of it's loop, and if it exists, it will kill itself.
        //
        // JobScheduler will also look for this file, and NOT execute the WorkerProcessManager job if it exists
        file_put_contents($this->appConfig->private_dir . '/storage/system/queue/stopworkers', time());


        $lastRunCount = 0;
        while(true)
        {
            $runningProcesses = [];
            foreach($data as $j)
                if ($this->isProcessRunning($j->pid))
                    $runningProcesses[] = $j->pid;

            if(count($runningProcesses) !== $lastRunCount)
                echo "Attempting to stop " . count($runningProcesses) . " worker processes....\n";

            $lastRunCount = count($runningProcesses);

            if(count($runningProcesses) === 0)
                break;
        }

    }

    private function isProcessRunning(int $pid) : bool
    {
        return file_exists("/proc/$pid");
    }
}