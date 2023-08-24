<?php

namespace Dren;

use Exception;


class FileLockableDataStore extends LockableDataStore
{
    private mixed $fileResource;
    private ?string $fileFullPath;

    public function __construct(string $directoryPath)
    {
        parent::__construct($directoryPath);

        $this->fileFullPath = null;
        $this->fileResource = null;

        // register a shutdown function so that we can insure file locks are released whenever the script
        // terminates, whether that be successfully or via error
        // Update: I'm told that file locks are released whenever script execution completes, but I'm not entirely
        // sure that's correct...and what does that mean even? Like, when the script exits or when gc runs? Leaving
        // this here because it makes me feel better.
//        register_shutdown_function(function() {
//
//            $this->closeLock();
//
//        });

    }

    public function openLock(string $id): bool
    {
        $fullPath = $this->containerName . '/' . $id;

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
        $fullPath = $this->containerName . '/' . $id;

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
        if($this->fileResource === null)
            return;

        if (!is_resource($this->fileResource))
            return;

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

        rewind($this->fileResource);

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

        fflush($this->fileResource);
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

        fflush($this->fileResource);
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
        file_put_contents($this->containerName . '/' . $id, $dataToWrite);
    }

    public function idExists(string $id): bool
    {
        return file_exists($this->containerName . '/' . $id);
    }

    public function idLocked(string $id): bool
    {
        $fullPath = $this->containerName . '/' . $id;

        $fp = @fopen($fullPath, 'r');

        if(!$fp)
            return false;

        if (!flock($fp, LOCK_EX|LOCK_NB))
        {
            fclose($fp);
            return true;
        }

        fclose($fp);
        return false;
    }

    public function deleteUnsafe(): void
    {
        if($this->fileFullPath !== null && file_exists($this->fileFullPath))
            unlink($this->fileFullPath);
    }

    public function deleteUnsafeById(string $id) : void
    {
        unlink($this->containerName . '/' . $id);
    }

    public function getContentsUnsafe(string $id): string
    {
        return file_get_contents($this->containerName . '/' . $id);
    }

    public function tryToLock(string $id): bool
    {
        $fullPath = $this->containerName . '/' . $id;

        // Open the file for reading and writing. Suppress the error here as we don't care about it since we're using
        // the error logic to determine if the file is present or not, and if not we're returning false
        $this->fileResource = @fopen($fullPath, 'r+');

        if($this->fileResource === false)
            return false;

        // Now, if file was opened successfully, try to get a lock without blocking.
        if (flock($this->fileResource, LOCK_EX | LOCK_NB) === false) // Attempt to acquire an exclusive lock, return immediately if one cannot be acquired
        {
            fclose($this->fileResource);
            return false;
        }

        $this->fileFullPath = $fullPath;

        return true;
    }

    public function getAllElementsInContainer(): array
    {
        if(!is_dir($this->containerName))
            return [];

        // Scan the directory for files
        $files = scandir($this->containerName);

        // Filter out the current and parent directory
        $files = array_diff($files, ['.', '..', '.gitkeep']);

        return $files;
    }
}