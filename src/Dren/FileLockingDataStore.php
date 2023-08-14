<?php

namespace Dren;

use Exception;

class FileLockingDataStore implements LockingDataStore
{
    private ?string $lockId;
    private mixed $fileResource;

    public function __construct()
    {
        $this->lockId = null;
        $this->fileResource = null;
    }

    public function openLock(string $lockId): bool
    {
        // Open the file for reading and writing. If it doesn't exist, create it.
        $this->fileResource = fopen($lockId, 'c+');

        // Check if there was a problem opening/creating the file
        if($this->fileResource === false)
            return false;

        // Now, if file was opened successfully, try to get a lock.
        if (flock($this->fileResource, LOCK_EX) === false) // Attempt to acquire an exclusive lock, block and wait if one cannot be acquired
            return false;

        $this->lockId = $lockId;

        return true;
    }

    public function openLockIfExists(string $lockId): bool
    {
        // Open the file for reading and writing. Suppress the error here as we don't care about it since we're using
        // the error logic to determine if the file is present or not, and if not we're returning false
        $this->fileResource = @fopen($lockId, 'r+');

        if($this->fileResource === false)
            return false;

        // Now, if file was opened successfully, try to get a lock.
        if (flock($this->fileResource, LOCK_EX) === false) // Attempt to acquire an exclusive lock, block and wait if one cannot be acquired
            return false;

        $this->lockId = $lockId;

        return true;
    }

    /**
     *
     * @return void
     * @throws Exception
     */
    public function closeLock(): void
    {
        if (!is_resource($this->fileResource))
            throw new Exception("Attempting to close file resource which is not valid.");

        $unlockSuccess = flock($this->fileResource, LOCK_UN);
        if (!$unlockSuccess)
            throw new Exception("Failed to unlock file.");

        $closeSuccess = fclose($this->fileResource);
        if (!$closeSuccess)
            throw new Exception("Failed to close file.");

        $this->fileResource = null;
    }

    /**
     * @throws Exception
     */
    public function getContents(): string
    {
        if(!$this->lockId)
            throw new Exception('Attempting to getContents of FileLockingDataStore which has no lock id');

        if(!$this->fileResource)
            throw new Exception('Attempting to getContents of FileLockingDataStore which has no File Resource');

        clearstatcache(true, $this->lockId); // Clear stat cache for the file
        $contents = fread($this->fileResource, filesize($this->lockId)); // Read the entire file

        if(!$contents)
            throw new Exception('Failed to retrieve file contents');

        return $contents;
    }

    /**
     *
     * @param string $dataToWrite
     * @return void
     * @throws Exception
     */
    public function overwriteContents(string $dataToWrite): void
    {
        if (!is_resource($this->fileResource))
            throw new Exception("Provided file resource is not valid.");

        $truncateSuccess = ftruncate($this->fileResource, 0);
        if (!$truncateSuccess)
            throw new Exception("Failed to truncate file.");

        $rewindSuccess = rewind($this->fileResource);
        if (!$rewindSuccess)
            throw new Exception("Failed to rewind file pointer.");

        $bytesWritten = fwrite($this->fileResource, $dataToWrite);
        if ($bytesWritten === false)
            throw new Exception("Failed to write to file.");
    }

    /**
     *
     * @param string $dataToWrite
     * @return void
     * @throws Exception
     */
    public function appendContents(string $dataToWrite): void
    {
        if (!is_resource($this->fileResource))
            throw new Exception("Provided file resource is not valid.");

        // Seek to the end of the file
        $seekSuccess = fseek($this->fileResource, 0, SEEK_END);
        if ($seekSuccess === -1)
            throw new Exception("Failed to seek to the end of the file.");

        $bytesWritten = fwrite($this->fileResource, $dataToWrite);
        if ($bytesWritten === false)
            throw new Exception("Failed to write to file.");
    }

}