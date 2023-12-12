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
use Mailgun\Model\MailingList\PagesResponse;

class CliAuthService implements AuthServiceInterface
{
    protected string $privateDir;
    protected AppConfig $config;
    private AccountDAO $accountDAO;


    /**
     * @throws Exception
     */
    public function __construct(string $privateDir, AppConfig $appConfig)
    {
        $this->privateDir = $privateDir;
        $this->config = $appConfig;
        $this->accountDAO = new AccountDAO();
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
     * Intended to be overridden in child class
     *
     * @param string $username
     * @return void
     */
    public function forgotPassword(string $username) : void {}

    /**
     * Intended to be overridden in child class
     *
     * @param string $username
     * @return void
     */
    public function initiateVerificationProcess(string $username) : void {}

    /**
     *
     *
     * @param string $token
     * @return bool
     * @throws Exception
     */
    public function verifyAccount(string $token) : bool
    {
        $username = $this->accountDAO->getUsernameFromVerificationToken($token);

        if($username === null)
            return false;

        $this->accountDAO->updateVerifiedAt($username);

        return true;
    }


    /**
     *
     *
     * @param string $resetToken
     * @return string|null
     * @throws Exception
     */
    public function getUsernameFromVerificationToken(string $resetToken) : ?string
    {
        return $this->accountDAO->getUsernameFromVerificationToken($resetToken);
    }

    /**
     *
     *
     * @param string $username
     * @param string $token
     * @return void
     * @throws Exception
     */
    public function createVerificationToken(string $username, string $token) : void
    {
        $this->accountDAO->createVerificationToken($username, $token);
    }

    /**
     *
     *
     * @param int $accountId
     * @param string $newPass
     * @return void
     * @throws Exception
     */
    public function updatePassword(int $accountId, string $newPass) : void
    {
        $this->accountDAO->updatePassword($accountId, password_hash($newPass, PASSWORD_DEFAULT));
    }

    /**
     *
     *
     * @param string $token
     * @return bool
     * @throws Exception
     */
    public function verificationTokenExists(string $token) : bool
    {
        if($this->accountDAO->getVerificationTokenDetails($token) === null)
            return false;

        return true;
    }

    /**
     *
     *
     * @return bool
     */
    public function hasRememberId() : bool
    {
        return false;
    }

    /**
     *
     *
     * @return void
     * @throws Exception
     */
    public function checkForRememberId(): void {}

    /**
     * @param string $username
     * @param string $password
     * @param ?string $ip
     * @param array<string> $roles
     * @return int
     * @throws Exception
     */
    public function createAccount(string $username, string $password, ?string $ip, array $roles = []) : int
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
     * @param string $ip
     * @param bool $remember
     * @return void
     * @throws Exception
     */
    public function upgradeSession(string $username, string $ip, bool $remember = false) : void {}

    /**
     *
     *
     * @return void
     * @throws Exception
     */
    public function logout() : void {}

}