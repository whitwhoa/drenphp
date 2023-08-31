<?php
declare(strict_types=1);

namespace Dren\Jobs;

use Dren\App;
use Dren\FileLockableDataStore;
use Dren\Job;
use Dren\LockableDataStore;
use Dren\Logger;
use Exception;

class WorkerProcessManager extends Job
{
    use SequentialJob;

    private object $queueConfig;
    private LockableDataStore $lockableDataStore;
    private ?string $dataStoreId;
    private string $workerScript;

    function __construct(mixed $data = null)
    {
        parent::__construct($data);

        if(App::get()->getConfig()->queue->lockable_datastore_type === 'file')
        {
            $this->dataStoreId = 'data.json';
            $this->lockableDataStore = new FileLockableDataStore(App::get()->getPrivateDir() . '/storage/system/queue');

            if(!$this->lockableDataStore->idExists($this->dataStoreId))
                $this->lockableDataStore->overwriteContentsUnsafe($this->dataStoreId, "[]");

            // we use "unsafe" or non-blocking reads/writes into the data store to ensure that we don't pass file descriptors/locks
            // to the child processes that this parent process spins up...yes, that's a thing. The functionality from the job
            // executor will ensure that only one of these jobs is running at a time anyway, therefore there is already no
            // chance of file read/write race conditions
        }
        else
        {
            $this->dataStoreId = null; // This is only used to initialize the member to some value, we never use it as null
            // TODO: addtional LockableDataStore functionality should be implemented here, for example when we add redis support
        }
        $this->queueConfig = App::get()->getConfig()->queue;
        $this->workerScript = App::get()->getPrivateDir() . '/worker';
    }

    function __destruct()
    {
        echo "destruct of WorkerProcessManager:" . getmypid() . "\n";
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
        $data = json_decode($this->lockableDataStore->getContentsUnsafe($this->dataStoreId));

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
                ((time() - $j->start_time) >= $this->queueConfig->queue_worker_lifetime)
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

        // re-index worker ids the array
        $workerIds = array_values($workerIds);

        // Start the required number of processes
        for ($i = 0; $i < count($workerIds); $i++)
        {
            $workerInt = $workerIds[$i];
            $pid = exec("php $this->workerScript $workerInt > /dev/null & echo $!");
            $data[] = [
                "pid" => $pid,
                "start_time" => time(),
                "worker_id" => $workerInt
            ];
        }

        // re-index the data array so keys are consecutive, meaning json_encode won't turn our array of objects
        // into an object of objects
        $data = array_values($data);

        $this->lockableDataStore->overwriteContentsUnsafe($this->dataStoreId, json_encode($data));
    }

    private function isProcessRunning(int $pid) : bool
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