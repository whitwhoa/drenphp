<?php

namespace Dren;

interface LockingDataStore
{
    /**
     * Open a lock. Create the data store element on which to lock if it does not exist
     *
     * @param string $lockId
     * @return void
     */
    public function openLock(string $lockId) : void;

    /**
     * Open a lock if it's data store already exists and return true. Return false if it does not
     *
     * @param string $lockId
     * @return bool
     */
    public function openLockIfExists(string $lockId) : bool;
}