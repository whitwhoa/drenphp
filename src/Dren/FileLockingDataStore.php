<?php

namespace Dren;

class FileLockingDataStore implements LockingDataStore
{
    private mixed $fileResource;

    public function __construct()
    {
        $this->fileResource = null;
    }

    public function openLockIfExists(string $lockId): bool
    {
        
    }


    public function openLock(string $lockId): void
    {
        // TODO: Implement this
    }
}