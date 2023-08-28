<?php

namespace Dren\Jobs;

use Dren\App;
use Dren\FileLockableDataStore;
use Dren\Job;
use Dren\LockableDataStore;
use Exception;

class WorkerProcessManager extends Job
{
    use SequentialJob;

    private object $queueConfig;
    private LockableDataStore $lockableDataStore;
    private string $workerScript;

    function __construct(mixed $data = null)
    {
        parent::__construct($data);

        if(App::get()->getConfig()->queue->lockable_datastore_type === 'file')
        {
            $this->lockableDataStore = new FileLockableDataStore(App::get()->getPrivateDir() . '/storage/system/queue');

            if(!$this->lockableDataStore->idExists('data.json'))
                $this->lockableDataStore->overwriteContentsUnsafe('data.json', "[]");

            $this->lockableDataStore->openLock('data.json');
        }
        $this->queueConfig = App::get()->getConfig()->queue;
        $this->workerScript = App::get()->getPrivateDir() . '/worker';
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
        $data = json_decode($this->lockableDataStore->getContents());

        $workerIds = [];
        for($i = 1; $i <= $this->queueConfig->queue_workers; $i++)
            $workerIds[] = $i;

        foreach($data as $index => $j)
        {
            if(!$this->isProcessRunning($j->pid))
            {
                unset($data[$index]);
                continue;
            }

            if(
                ((time() - $j->start_time) > $this->queueConfig->queue_worker_lifetime)
                ||
                ($j->last_memory_usage > $this->queueConfig->mem_before_restart)
            ){
                $this->killProcess($j->pid);
                unset($data[$index]);
                continue;
            }

            foreach($workerIds as $widIndex => $widValue)
                if($widValue == $j->worker_id)
                    unset($workerIds[$widIndex]);
        }

        // re-index the array
        $workerIds = array_values($workerIds);

        // Start the required number of processes
        for ($i = 0; $i < count($workerIds); $i++)
        {
            $workerInt = $workerIds[$i];
            $pid = exec("php $this->workerScript $workerInt > /dev/null & echo $!");
            $data[] = [
                "pid" => $pid,
                "start_time" => time(),
                "worker_id" => $workerInt,
                "last_memory_usage" => 0
            ];
        }

        $this->lockableDataStore->overwriteContents(json_encode($data));
    }

    private function isProcessRunning($pid) : bool
    {
        return file_exists("/proc/$pid");
    }

    private function killProcess(int $pid) : bool
    {
        $output = [];
        $returnVar = null;
        exec("kill $pid", $output, $returnVar);

        return $returnVar === 0;
    }


}