<?php

namespace Dren;

interface LockableDataStore
{
    /**
     * Open a lock. Create the data store element on which to lock if it does not exist
     *
     * @param string $id
     * @return bool
     */
    public function openLock(string $id) : bool;


    /**
     * Open a lock if it's data store already exists and return true. Return false if it does not
     *
     * @param string $id
     * @return bool
     */
    public function openLockIfExists(string $id) : bool;


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
     * Overwrite the contents of the datastore belonging to $id, with $dataToWrite, without performing any locking
     *
     * @param string $id
     * @param string $dataToWrite
     * @return void
     */
    public function overwriteContentsUnsafe(string $id, string $dataToWrite) : void;


    /**
     * Append to the existing contents of the data store, the contents of the provided parameter
     *
     * @param string $dataToWrite
     * @return void
     */
    public function appendContents(string $dataToWrite) : void;


    /**
     * Copy ownership of the existing LockableDataStore to another instance
     *
     * @return LockableDataStore
     */
    public function copyOwnership() : LockableDataStore;


}