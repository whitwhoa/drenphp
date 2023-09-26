<?php

namespace Dren\Services;

use Dren\App;
use Dren\Configs\AppConfig;
use Dren\DAOs\AccountDAO;
use Dren\FileLockableDataStore;
use Dren\LockableDataStore;
use Dren\MySQLCon;
use Dren\RememberIdManager;
use Dren\Request;
use Dren\SecurityUtility;
use Dren\SessionManager;
use Exception;

class AuthService
{
    private string $privateDir;
    private AppConfig $config;
    private RememberIdManager $ridManager;
    private AccountDAO $accountDAO;
    protected SessionManager $sm;
    private ?LockableDataStore $ridLock;

    /**
     * @throws Exception
     */
    public function __construct(string $privateDir, AppConfig $appConfig, Request $request, MySQLCon $db, SecurityUtility $su, SessionManager $sm)
    {
        $this->privateDir = $privateDir;
        $this->config = $appConfig;
        $this->ridManager = new RememberIdManager($appConfig, $request, $db, $su);
        $this->accountDAO = new AccountDAO();
        $this->sm = $sm;
        $this->ridLock = null;
    }

    /**
     * Intended to be overridden in child class
     *
     * @param int $accountId
     * @param string $username
     * @param array<string> $roles
     * @return void
     */
    public function onSessionUpgrade(int $accountId, string $username, array $roles) : void {}

    /**
     *
     *
     * @return void
     * @throws Exception
     */
    public function checkForRememberId(): void
    {
        // Check for remember_id token and attempt to re-authenticate the account if one is found.
        $this->ridManager->setRememberId();

        if(!$this->sm->getSessionId() && $this->ridManager->hasRememberId())
        {
            $rememberId = $this->ridManager->getRememberId();
            if($rememberId === null)
                throw new Exception("Remember Id cannot be null");

            if($this->config->lockable_datastore_type === 'file')
                $this->ridLock = new FileLockableDataStore($this->privateDir . '/storage/system/locks/rid');
            // TODO: add logic for additional LockableDataStores

            if($this->ridLock  === null)
                throw new Exception("Unable to retrieve a remember id lock");

            $this->ridLock->openLock($rememberId);
            $this->ridLock->overwriteContents((string)time());

            $existingToken = $this->ridManager->getRememberIdSession();

            if($existingToken !== null && $this->sm->dataStoreExists($existingToken))
            {
                $this->sm->useSessionId($existingToken);
            }
            else
            {
                $account = $this->ridManager->getRememberIdAccount();
                $this->sm->startNewSession($account->account_id, $account->username, $account->roles);

                $this->onSessionUpgrade($account->account_id, $account->username, $account->roles);

                $this->sm->persist();

                $sid = $this->sm->getSessionId();
                if($sid === null)
                    throw new Exception("Session id cannot be null");

                $this->ridManager->associateSessionIdWithRememberId($sid);
            }

            $this->ridLock->closeLock();
        }
    }

    /**
     * @throws Exception
     */
    public function createAccount(string $username, string $password, string $ip, array $roles = []) : int
    {
        return $this->accountDAO->createNewAccount(
            $username,
            password_hash($password, PASSWORD_DEFAULT),
            $ip,
            $roles
        );
    }

    /**
     *
     *
     * @param string $username
     * @param string $password
     * @return bool
     * @throws Exception
     */
    public function authenticate(string $username, string $password) : bool
    {
        $u = $this->accountDAO->getAccountByUsername($username);

        if(!$u)
            return false;

        if(!password_verify($password, $u->password))
            return false;

        return true;
    }

    /**
     *
     *
     * @param string $username
     * @param bool $remember
     * @return void
     * @throws Exception
     */
    public function upgradeSession(string $username, bool $remember = false) : void
    {
        $account = $this->accountDAO->getAccountByUsername($username);

        if($account === null)
            throw new Exception('Account does not exist for provided username');

        if($remember)
            $this->ridManager->createNewRememberId($account->id);

        $roles = $this->accountDAO->getRoles($account->id);

        $this->sm->upgradeSession($account->id, $account->username, $roles);

        $this->onSessionUpgrade($account->id, $account->username, $roles);
    }

    /**
     *
     *
     * @return void
     * @throws Exception
     */
    public function logout() : void
    {
        // if user has remember_id, remove it from database and send response to remove token from client
        $this->ridManager->clearRememberId();

        // send response to remove session_id from client, token stays on server until cleared by gc in case there
        // are any concurrent requests in the queue, AND since we're currently blocking on this file we could delete
        // it on unix systems and any concurrent requests waiting for locks would function just fine because of how
        // unix handles file pointers, windows is a different story and I can see development on windows platform
        // then deploying to linux being common.
        $this->sm->removeClientSessionId();
    }

}