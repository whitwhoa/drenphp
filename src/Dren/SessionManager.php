<?php
declare(strict_types=1);


namespace Dren;


use Exception;

class SessionManager
{
    private SessionConfig $sessionConfig;
    private SecurityUtility $securityUtility;
    private ?Request $request;
    private ?string $sessionId;
    private ?LockableDataStore $tmpLockableDataStore;
    private ?LockableDataStore $sessionLockableDataStore;
    private ?object $session;

    private ?object $flashed;

    public function __construct(AppConfig $appConfig, SecurityUtility $su)
    {
        $this->sessionConfig = $appConfig->session;
        $this->securityUtility = $su;
        $this->sessionId = null;
        $this->request = null; // null here because we can't completely initialize until after we receive request

        if($appConfig->lockable_datastore_type === 'file')
        {
            $this->tmpLockableDataStore = new FileLockableDataStore($this->sessionConfig->directory);
            $this->sessionLockableDataStore = new FileLockableDataStore($this->sessionConfig->directory);
        }
        // TODO: once we implement redis functionality, that check will go here

        $this->session = null;
        $this->flashed = null;
    }

    /**
     * Executed first within App. This happens for every request.
     *
     * @throws Exception
     */
    public function loadSession(Request $request) : void
    {
        try
        {
            $this->request = $request;

            $this->getTokenFromClient();

            if($this->sessionId !== null)
                $this->updateSession();
        }
        catch (Exception $e)
        {
            throw new Exception('Something went wrong attempting to load session: ' . $e->getMessage());
        }
    }

    /**
     * Used by App::execute to force usage of a provided session id. This would be used for the case where a user's
     * session expires, but they have a remember_id, and tha remember_id was previously used to generate a session_id.
     * Really only relevant if the user did not receive the initial response containing the new session_id, or if
     * they had concurrent requests in flight whenever it was issued.
     *
     * @param string $sessionId
     * @return void
     * @throws Exception
     */
    public function useSessionId(string $sessionId) : void
    {
        $this->sessionId = $sessionId;
        $this->setClientSessionId();
        $this->updateSession();
    }

    public function dataStoreExists(string $sessionId) : bool
    {
        return $this->tmpLockableDataStore->idExists($sessionId);
    }

    private function getTokenFromClient(): void
    {
        $unverified_session_id = null;

        if($this->request->getRoute()->getRouteType() === 'web')
        {
            if($this->request->getCookie($this->sessionConfig->web_client_name))
                $unverified_session_id = $this->securityUtility->decryptAndVerifyToken(
                    $this->request->getCookie($this->sessionConfig->web_client_name));
        }
        elseif($this->request->getRoute()->getRouteType() === 'mobile')
        {
            if($this->request->getHeader($this->sessionConfig->mobile_client_name))
                $unverified_session_id = $this->securityUtility->decryptAndVerifyToken(
                    $this->request->getHeader($this->sessionConfig->mobile_client_name));
        }

        // At this point, if session_id token has been provided, it has been decrypted and its signature
        // has been verified.
        $this->sessionId = $unverified_session_id;
    }

    /**
     * @throws Exception
     */
    private function updateSession() : void
    {
        // If we were unable to obtain a lockable data store, then it's likely that it has been removed, so we treat
        // this request as if it did not send a session token.
        if(!$this->tmpLockableDataStore->openLockIfExists($this->sessionId))
        {
            $this->sessionId = null;
            return;
        }

        // NOW BLOCKING ON PROVIDED SESSION ID

        // If we've made it here, the lockable data store exists, so let's load it's content into memory
        $this->session = json_decode($this->tmpLockableDataStore->getContents());

        // Has session token expired?
        if(((int)$this->session->issued_at + (int)$this->session->valid_for) > time())
        {
            // Token still valid

            // Copy ownership from tmpLockableDataStore member to sessionLockableDataStore member to be utilized elsewhere
            $this->sessionLockableDataStore = $this->tmpLockableDataStore->copyOwnership();

            $this->loadFlashed();

            // If not blocking, then update the session's last_used property and release the lock
            if(!$this->request->getRoute()->isBlocking())
                $this->terminate();

            // If blocking, nothing further required as the lock will remain open throughout the request and terminated
            // right before sending the response
            return;
        }

        // If we have made it here, then the session token has expired

        // If reissued_at is not null, then the current token is a token which has previously been reissued, which
        // we can take to mean that there were multiple concurrent requests in flight during the re-issuance process,
        // or that the connection was lost before the client could receive the new token.
        if($this->session->reissued_at !== null)
        {
            // Check if this token is still within its liminal state. If it is not, then this token has expired, and
            // we want to set the token to null and treat this as a request which did not supply a token. If it is,
            // then we set this session_id equal to the updated_token value, release the lock on the expired token file
            // and obtain a lock for the updated_token
            if(((int)$this->session->reissued_at + (int)$this->session->liminal_time) < time())
            {
                // invalid
                $this->session = null;
                $this->tmpLockableDataStore->closeLock();
                $this->sessionId = null;
                return;
            }

            $this->sessionId = $this->session->updated_token;
            if(!$this->sessionLockableDataStore->openLockIfExists($this->sessionId))
                throw new Exception('Unable to open session lock for token. This should never happen.');

            $this->session = json_decode($this->sessionLockableDataStore->getContents());

            $this->tmpLockableDataStore->closeLock();

            $this->setClientSessionId();

            $this->loadFlashed();

            if(!$this->request->getRoute()->isBlocking())
                $this->terminate();

            return;
        }

        // If we've made it here, then the token has expired, and has not been previously re-issued while still being
        // within it's liminal state. We must check to verify that the token is still valid for re-issuance (based off
        // of inactivity). If it is not, we treat the request as though no session token was provided...
        if((time() - $this->session->last_used) > $this->session->allowed_inactivity)
        {
            // not valid for re-issuance
            $this->session = null;
            $this->tmpLockableDataStore->closeLock();
            $this->sessionId = null;
            return;
        }

        //Logger::debug('Regenerating session token due to ttl expiration');

        // ...if it is, we generate a new token and copy the data from this session into the new
        // session, then take the new sessionId and save it as the updated_token property within the old session,
        // update the old session's regenerated_at property, then set the new token to be issued to the client in
        // response
        $newToken = $this->generateNewSession();

        $account_id = $this->session->account_id;
        $account_roles = $this->session->account_roles;
        $flash_data = $this->session->flash_data;
        $data = $this->session->data;

        $this->session->reissued_at = time();
        $this->session->updated_token = $newToken;

        $encodedSessionData = json_encode($this->session);
        if($encodedSessionData === false)
            throw new Exception("Unable to encode session data");

        $this->tmpLockableDataStore->overwriteContents($encodedSessionData);

        // open lock on new session token
        $this->sessionId = $newToken;
        if(!$this->sessionLockableDataStore->openLockIfExists($this->sessionId))
            throw new Exception('Unable to open session lock for token. This should never happen.');

        $this->session = json_decode($this->sessionLockableDataStore->getContents());

        // update required parameters
        $this->session->account_id = $account_id;
        $this->session->account_roles = $account_roles;
        $this->session->flash_data = $flash_data;
        $this->session->data = $data;

        // write updated session to file
        $encodedSessionData = json_encode($this->session);
        if($encodedSessionData === false)
            throw new Exception("Unable to encode session data");

        $this->sessionLockableDataStore->overwriteContents($encodedSessionData);

        // release lock on old session token
        $this->tmpLockableDataStore->closeLock();

        // attach new token to response
        $this->setClientSessionId();

        $this->loadFlashed();

        if(!$this->request->getRoute()->isBlocking())
            $this->terminate();
    }

    private function loadFlashed() : void
    {
        $this->flashed = $this->session->flash_data;
        $this->session->flash_data = (object)[];
    }

    /**
     * @param int $accountId
     * @param array<string> $roles
     * @return void
     * @throws Exception
     */
    public function upgradeSession(int $accountId, array $roles) : void
    {
        if(!$this->request->getRoute()->isBlocking())
            throw new Exception('You are attempting to upgrade a session in a non-blocking route. This is not allowed.');

        //Logger::debug('Regenerating session due to successful account authentication');

        $newToken = $this->generateNewSession();

        $flashData = $this->session->flash_data;
        $data = $this->session->data;

        $this->session->reissued_at = time();
        $this->session->updated_token = $newToken;

        $this->sessionLockableDataStore->overwriteContents(json_encode($this->session));

        // copy the ownership of the existing sessionLockableDataStore file pointer to tmpLockableDataStore
        // so that we can clear the lock later
        $this->tmpLockableDataStore = $this->sessionLockableDataStore->copyOwnership();

        $this->sessionId = $newToken;
        if(!$this->sessionLockableDataStore->openLockIfExists($this->sessionId))
            throw new Exception('Unable to open session lock for token. This should never happen.');
        $this->session = json_decode($this->sessionLockableDataStore->getContents());

        $this->session->account_id = $accountId;
        $this->session->account_roles = $roles;
        $this->session->flash_data = $flashData;
        $this->session->data = $data;

        $this->sessionLockableDataStore->overwriteContents(json_encode($this->session));
        $this->tmpLockableDataStore->closeLock();
        $this->setClientSessionId();
    }

    /**
     * Generates a new session token and corresponding filesystem datastore, then returns token. Does not
     * set token in response data, that needs to be handled by calling function if it is required
     *
     * @param int|null $accountId
     * @param array<string> $accountRoles
     * @return string
     * @throws Exception
     */
    private function generateNewSession(?int $accountId = null, array $accountRoles = []) : string
    {
        // Generate the new signed session_id token
        $token = $this->securityUtility->generateSignedToken();

        $sessionInfo = (object)[
            'account_id' => $accountId,
            'account_roles' => $accountRoles,
            'issued_at' => time(),
            'last_used' => time(),
            'valid_for' => $this->sessionConfig->valid_for,
            'liminal_time' => $this->sessionConfig->liminal_time,
            'allowed_inactivity' => $this->sessionConfig->allowed_inactivity,
            'reissued_at' => null,
            'updated_token' => null,
            'csrf' => uuid_create_v4(),
            'flash_data' => (object)[],
            'data' => (object)[]
        ];

        $this->sessionLockableDataStore->overwriteContentsUnsafe($token, json_encode($sessionInfo));

        // We return the token here so that the calling function can dictate what we do with it, since logic differs
        // between strictly new first time generations, and updates, on updates we have to modify the data with the
        // previous session's data and then lock on the new file, whereas a new generation does not require this
        // (because it's the first request generating the session and the client has yet to acknowledge that it has
        // received the token by sending another request.)

        return $token;
    }

    /**
     * Encrypts token value and adds either set cookie header or custom header based on what type of route this is
     *
     * @return void
     */
    private function setClientSessionId() : void
    {
        // encrypt token for storage on client
        $encryptedToken = $this->securityUtility->encryptString($this->sessionId);

        if($this->request->getRoute()->getRouteType() === 'web')
        {
            setcookie(
                $this->sessionConfig->web_client_name,
                $encryptedToken,
                [
                    'expires' => time() + $this->sessionConfig->allowed_inactivity,
                    'path' => '/',
                    'secure' => $this->sessionConfig->cookie_secure,
                    'httponly' => $this->sessionConfig->cookie_httponly,  // HttpOnly flag
                    'samesite' => $this->sessionConfig->cookie_samesite  // SameSite attribute
                ]
            );
        }
        elseif($this->request->getRoute()->getRouteType() === 'mobile')
        {
            header($this->sessionConfig->mobile_client_name . ': ' . $encryptedToken);
        }
    }

    public function removeClientSessionId() : void
    {
        if($this->request->getRoute()->getRouteType() === 'web')
        {
            setcookie(
                $this->sessionConfig->web_client_name,
                '',
                [
                    'expires' => time() - 3600,
                    'path' => '/',
                    'secure' => $this->sessionConfig->cookie_secure,
                    'httponly' => $this->sessionConfig->cookie_httponly,  // HttpOnly flag
                    'samesite' => $this->sessionConfig->cookie_samesite  // SameSite attribute
                ]
            );
        }
        elseif($this->request->getRoute()->getRouteType() === 'mobile')
        {
            header($this->sessionConfig->mobile_client_name . ': ' . 'REMOVE');
        }
    }

    /**
     * Used by other classes to generate a new session, and set client headers.
     *
     * We don't block on newly generated session because the client has yet to acknowledge receiving the token,
     * therefore there's no point. We just need to ensure that we handle cases further up the stack where a user might
     * attempt to do a post request without a session_id, or with an expired session_id. I'm thinking this will be
     * handled by the csrf protection however, because if a user does a post without a session, we'll wind up creating
     * a new session with a new csrf token, and since that token won't match what's being submitted...probably solved?
     * We would just handle it the same way we would if the csrf token had expired, which hell it would be anyway, it's
     * the same thing...
     *
     * @param int|null $accountId
     * @param array<string> $accountRoles
     * @return void
     * @throws Exception
     */
    public function startNewSession(?int $accountId = null, array $accountRoles = []) : void
    {
        $this->sessionId = $this->generateNewSession($accountId, $accountRoles);

        if(!$this->sessionLockableDataStore->openLockIfExists($this->sessionId))
            throw new Exception('Unable to open session lock for token. This should never happen.');

        $this->session = json_decode($this->sessionLockableDataStore->getContents());

        // new sessions should always block because we don't know what could be writing to them, and they need to be
        // persisted at the end of the request. Leaving this here for the time being, will come back and remove
        // at a future date.
        //$this->sessionLockableDataStore->closeLock();

        $this->setClientSessionId();
    }

    /**
     * Updates the session's last_used property, persists session to file data store, releases file lock
     *
     * @return void
     * @throws Exception
     */
    private function terminate() : void
    {
        $this->session->last_used = time();
        $this->sessionLockableDataStore->overwriteContents(json_encode($this->session));
        $this->sessionLockableDataStore->closeLock();
    }

    /**
     * Basically same thing as terminate(), but checks if request is blocking, and follows the assumption that any
     * non-blocking requests would have already been handled
     *
     * @return void
     * @throws Exception
     */
    public function finalizeSessionState() : void
    {
        if(!$this->sessionId)
            return;

        if(!$this->request->getRoute()->isBlocking())
            return;

        $this->terminate();
    }

    public function saveFlash(string $key, mixed $data) : void
    {
        if(!$this->sessionId)
            $this->startNewSession();

        $this->session->flash_data->{$key} = $data;
    }

    public function getFlash(string $key) : mixed
    {
        if(!$this->sessionId || !isset($this->flashed->{$key}))
            return null;

        return $this->flashed->{$key};
    }

    public function saveData(string $key, mixed $data) : void
    {
        if(!$this->sessionId)
            $this->startNewSession();

        $this->session->data->{$key} = $data;
    }

    public function getData(string $key) : mixed
    {
        if(!$this->sessionId || !isset($this->session->data->{$key}))
            return null;

        return $this->session->data->{$key};
    }

    public function getCsrf() : string
    {
        if(!$this->sessionId)
            $this->startNewSession();

        return $this->session->csrf;
    }

    public function isAuthenticated() : bool
    {
        if(!$this->sessionId)
            return false;

        if(!$this->session->account_id)
            return false;

        return true;
    }

    public function getAccountId() : ?int
    {
        if(!$this->session->account_id)
            return null;

        return $this->session->account_id;
    }

    public function getSessionId() : ?string
    {
        return $this->sessionId;
    }



}