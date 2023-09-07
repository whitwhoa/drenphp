<?php

namespace Dren;

use Dren\Configs\AppConfig;
use Exception;

class GC
{
    private AppConfig $appConfig;

    private LockableDataStore $sessionLDS;
    private LockableDataStore $rememberIdLDS;
    private LockableDataStore $ipLDS;

    private function __construct()
    {
        $this->appConfig = App::get()->getConfig();

        // TODO: add this back if we ever actually implement a redis LockableDataStore
//        if($this->appConfig->lockable_datastore_type === 'file')
//        {
            $this->sessionLDS = new FileLockableDataStore($this->appConfig->session->directory);
            $this->rememberIdLDS = new FileLockableDataStore($this->appConfig->private_dir . '/storage/system/locks/rid');
            $this->ipLDS = new FileLockableDataStore($this->appConfig->private_dir . '/storage/system/locks/ip');
//        }

    }

    /**
     * @throws Exception
     */
    public static function run() : void
    {
        $gc = new self();

        // With sessions, it's safe to read the contents, and then if certain parameters are met we can trash
        // the data store with certainty that nothing is ever going to try to grab that file and use it again
        foreach($gc->sessionLDS->getAllElementsInContainer() as $sessionToken)
        {
            $sessionData = Session::generateFromJson($gc->sessionLDS->getContentsUnsafe($sessionToken));

            if
            (
                // session token has been re-issued, and liminal time has passed, get rid of token
                ($sessionData->reissuedAt !== null && ($sessionData->reissuedAt + $sessionData->liminalTime) < time())
                ||
                // if session inactivity period has been reached, get rid of token
                ($sessionData->allowedInactivity >= (time() - $sessionData->lastUsed))
            )
            {
                $gc->sessionLDS->deleteUnsafeById($sessionToken);
            }
        }

        // For the remember ID and ip locks, it's just best effort...do what you can, and hopefully if on windows things
        // won't crash (can't lock and delete because windows will throw a fit)
        foreach($gc->rememberIdLDS->getAllElementsInContainer() as $rememberId)
        {
            $createdAt = intval($gc->rememberIdLDS->getContentsUnsafe($rememberId));

            if($createdAt === 0)
                throw new Exception("Unable to parse time value within remember id datastore");

            if(!$gc->rememberIdLDS->idLocked($rememberId) && (time() - $createdAt) >= 15)
                $gc->rememberIdLDS->deleteUnsafeById($rememberId);
        }

        foreach($gc->ipLDS->getAllElementsInContainer() as $ip)
        {
            $createdAt = intval($gc->ipLDS->getContentsUnsafe($ip));

            if($createdAt === 0)
                throw new Exception("Unable to parse time value within ip datastore");

            if(!$gc->ipLDS->idLocked($ip) && (time() - $createdAt) >= 15)
                $gc->ipLDS->deleteUnsafeById($ip);
        }

    }
}