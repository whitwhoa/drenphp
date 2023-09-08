<?php
declare(strict_types=1);

namespace Dren\Jobs;

use Dren\Configs\AppConfig;
use Dren\Job;
use Dren\Jobs\ExecutionTypes\SequentialJob;
use Dren\App;
use Exception;

class StartWorkers extends Job
{
    use SequentialJob;

    private AppConfig $appConfig;


    function __construct(mixed $data = null)
    {
        parent::__construct($data);
        //$this->trackExecution = false;

        $this->appConfig = App::get()->getConfig();

    }

    public function preCondition(): bool
    {
        return true;
    }


    public function logic(): void
    {
        if(file_exists($this->appConfig->private_dir . '/storage/system/queue/stopworkers'))
            unlink($this->appConfig->private_dir . '/storage/system/queue/stopworkers');

        $runJobScript = $this->appConfig->private_dir . '/runjob';
        exec("php " . $runJobScript . " WorkerProcessManager");
    }

}