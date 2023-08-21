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
     * Open a lock if it's data store already exists and return true. Return false if it does not. Block until
     * lock can be obtained.
     *
     * @param string $id
     * @return bool
     */
    public function openLockIfExists(string $id) : bool;

    /**
     * Same thing as openLockIfExists(string $id), only we don't block if we cannot get the lock, we return false
     *
     * @param string $id
     * @return bool
     */
    public function tryToLock(string $id) : bool;

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
     * Read contents of file without previously acquiring a lock. Only use this if you know why you are using it
     * specifically, and not one of the other methods
     *
     * @param string $id
     * @return string
     */
    public function getContentsUnsafe(string $id) : string;

    /**
     * Overwrite the entire contents of the data store with the contents of the provided parameter
     *
     * @param string $dataToWrite
     * @return void
     */
    public function overwriteContents(string $dataToWrite) : void;

    /**
     * Overwrite the contents of the datastore belonging to $id, with $dataToWrite, without performing any locking.
     * You need to know exactly why you are using this before you would ever use it.
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

    /**
     * Check if the provided $id is an existing LockableDataStore
     *
     * @param string $id
     * @return bool
     */
    public function idExists(string $id) : bool;


    /**
     * Determines if the provided $id is currently locked
     *
     * @param string $id
     * @return bool
     */
    public function idLocked(string $id) : bool;


    /**
     * Attempts to release the lock and immediately delete the datastore. Only use this if you know exactly
     * why you are using it, or you will introduce race conditions into the application.
     *
     * The reason this is unsafe is that if there are other locks open waiting for this resource, they could
     * obtain their lock in-between the steps where we release the lock and delete the file. This method is only
     * intended to be used when you know that that won't happen.
     *
     * @return void
     */
    public function deleteUnsafe() : void;


}