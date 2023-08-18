<?php

namespace Dren;

use Exception;

abstract class Job
{
    protected ?string $data;

    function __construct(?string $data = null)
    {
        $this->data = !$data ? null : json_decode($data);
    }

    abstract public function preCondition() : bool;

	abstract public function logic() : void;

	public function run(bool $canRun = true) : bool
    {
        try
        {
            // If canRun is false, then it's likely that a job which this job depends on has failed,
            // and it has returned false, therefore we will not process the logic of this job
            if(!$canRun)
                return false;

            // If we've made it here it means that canRun is true, now if shouldRun is false, that means
            // that the preCondition method has returned false, which would indicate that there is no reason
            // to run this job, it hasn't failed, it just doesn't need to be run at this time. An example would
            // be if the job is dependent on a file existing that's uploaded, but it doesn't exist because some
            // other process hasn't provided it yet, the preCondition method would execute and check if it exists
            // and only return true if it does, indicating that it's correct to proceed
            if(!$this->preCondition())
                return true;

            $this->logic();
        }
        catch(Exception $e)
        {
            // TODO: This will probably require additional logic
            Logger::write($e->getMessage() . ":" . $e->getTraceAsString());
            return false;
        }

        return true;
    }

}