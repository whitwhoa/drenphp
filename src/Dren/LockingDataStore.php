<?php

namespace Dren;

interface LockingDataStore
{
    /**
     * Open a lock. Create the data store element on which to lock if it does not exist
     *
     * @param string $lockId
     * @return bool
     */
    public function openLock(string $lockId) : bool;


    /**
     * Open a lock if it's data store already exists and return true. Return false if it does not
     *
     * @param string $lockId
     * @return bool
     */
    public function openLockIfExists(string $lockId) : bool;


    /**
     * Close the lock
     *
     * @return void
     */
    public function closeLock() : void;


    /**
     * Read contents of locked data store resource and return as string
     *
     * @return mixed
     */
    public function getContents() : string;


    /**
     * Overwrite the entire contents of the data store with the contents of the provided parameter
     *
     * @param string $dataToWrite
     * @return void
     */
    public function overwriteContents(string $dataToWrite) : void;


    /**
     * Append to the existing contents of the data store, the contents of the provided parameter
     *
     * @param string $dataToWrite
     * @return void
     */
    public function appendContents(string $dataToWrite) : void;
}