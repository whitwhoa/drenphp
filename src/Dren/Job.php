<?php

namespace Dren;

use Exception;

abstract class Job
{
    protected mixed $data; // decoded json, so either an array, an stdClass, or null

    function __construct(mixed $data = null)
    {
        $this->data = !$data ? null : $data;
    }

    abstract public function preCondition() : bool;

	abstract public function logic() : void;

	public function run() : bool
    {
        try
        {
            // if preCondition() fails, there is no reason to run this job, it hasn't failed, it just doesn't need to
            // be run at this time. An example would be if the job is dependent on a file existing that's uploaded, but
            // it doesn't exist because some other process hasn't provided it yet, the preCondition method would
            // execute and check if it exists and only return true if it does, indicating that it's correct to proceed
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