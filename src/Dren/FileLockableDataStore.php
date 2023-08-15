<?php

namespace Dren;

use Exception;

class FileLockableDataStore implements LockableDataStore
{
    private string $directoryPath;
    private mixed $fileResource;
    private ?string $fileFullPath;

    public function __construct(string $directoryPath)
    {
        $this->directoryPath = $directoryPath;
        $this->fileFullPath = null;
        $this->fileResource = null;

        // register a shutdown function so that we can insure file locks are released whenever the script
        // terminates, whether that be successfully or via error
        // Update: I'm told that file locks are released whenever script execution completes, but I'm not entirely
        // sure that's correct...and what does that mean even? Like, when the script exits or when gc runs? Leaving
        // this here because it makes me feel better.
        register_shutdown_function(function() {

            if($this->fileResource !== null)
                $this->closeLock();

        });

    }

    public function openLock(string $id): bool
    {
        $fullPath = $this->directoryPath . '/' . $id;

        // Open the file for reading and writing. If it doesn't exist, create it.
        $this->fileResource = fopen($fullPath, 'c+');

        // Check if there was a problem opening/creating the file
        if($this->fileResource === false)
            return false;

        // Now, if file was opened successfully, try to get a lock.
        if (flock($this->fileResource, LOCK_EX) === false) // Attempt to acquire an exclusive lock, block and wait if one cannot be acquired
            return false;

        $this->fileFullPath = $fullPath;

        return true;
    }

    public function openLockIfExists(string $id): bool
    {
        $fullPath = $this->directoryPath . '/' . $id;

        // Open the file for reading and writing. Suppress the error here as we don't care about it since we're using
        // the error logic to determine if the file is present or not, and if not we're returning false
        $this->fileResource = @fopen($fullPath, 'r+');

        if($this->fileResource === false)
            return false;

        // Now, if file was opened successfully, try to get a lock.
        if (flock($this->fileResource, LOCK_EX) === false) // Attempt to acquire an exclusive lock, block and wait if one cannot be acquired
            return false;

        $this->fileFullPath = $fullPath;

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
        if(!$this->fileFullPath)
            throw new Exception('Attempting to getContents of FileLockingDataStore which has no lock id');

        if(!$this->fileResource)
            throw new Exception('Attempting to getContents of FileLockingDataStore which has no File Resource');

        clearstatcache(true, $this->fileFullPath); // Clear stat cache for the file
        $contents = fread($this->fileResource, filesize($this->fileFullPath)); // Read the entire file

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

    public function copyOwnership(): LockableDataStore
    {
        // Clone the current object
        $copy = clone $this;

        // Set the original object's resource to null, as the variable holding the return object
        // will be the new owner of the resource pointer
        $this->fileResource = null;

        // Return the cloned, unchanged copy
        return $copy;
    }

    public function overwriteContentsUnsafe(string $id, string $dataToWrite): void
    {
        file_put_contents($this->directoryPath . '/' . $id, $dataToWrite);
    }
}