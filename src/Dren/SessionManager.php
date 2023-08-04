<?php


namespace Dren;


use Exception;

class SessionManager
{
    private object $config;
    private SecurityUtility $securityUtility;
    private ?Request $request;
    private ?string $sessionId;

    /**
     * @var resource
     */
    private mixed $sessionFileResource;

    private ?object $session;

    public function __construct(object $sessionConfig, SecurityUtility $su)
    {
        $this->config = $sessionConfig;
        $this->securityUtility = $su;
        $this->sessionId = null;
        $this->request = null; // null here because we can't completely initialize until after we receive request
        $this->sessionFileResource = null;
        $this->session = null;
    }

    public function init(Request $request)
    {
        $this->request = $request;

        $this->getTokenFromClient();

        if($this->sessionId !== null)
        {
            // if we have made it here, then the session_id is valid, and there is a corresponding file for it
            // update session
            // return
        }
    }

    private function getTokenFromClient(): void
    {
        $unverified_session_id = null;

        if($this->request->getRoute()->getRouteType() === 'web')
        {
            if($this->request->getCookie($this->config->web_client_name))
                $unverified_session_id = $this->securityUtility->decryptAndVerifyToken(
                    $this->request->getCookie($this->config->web_client_name));
        }
        elseif($this->request->getRoute()->getRouteType() === 'mobile')
        {
            if($this->request->getHeader($this->config->mobile_client_name))
                $unverified_session_id = $this->securityUtility->decryptAndVerifyToken(
                    $this->request->getHeader($this->config->mobile_client_name));
        }

        // if session id was provided, but it's corresponding file has been removed from the system, set it to null
        if($unverified_session_id !== null && !file_exists($this->config->directory . '/' . $unverified_session_id))
            $unverified_session_id = null;

        // At this point, if session_id token has been provided, it has been decrypted and its signature has been
        // verified. It has also been verified that the corresponding entry within its datastore still exists.
        $this->sessionId = $unverified_session_id;
    }

    private function updateSession(): void
    {
        $fileResource = $this->openSessionLock($this->sessionId);

        // content of session file data store loaded into memory as $this->session value
        // holding lock for $this->sessionId file

        // Has session token expired?
        if(($this->session->issued_at + $this->session->valid_for) < time())
        {
            // Token still valid

            // Hold file resource in member variable
            $this->sessionFileResource = $fileResource;

            // If GET request, then update the session's last_used property and release the lock
            if($this->request->getRoute()->getRequestMethod() === 'GET')
                $this->terminate();

            // If POST nothing further required as the lock will remain open throughout the request and terminated
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
            if(($this->session->reissued_at + $this->session->liminal_time) < time())
            {
                // invalid
                $this->session = null;
                $this->closeFileAndReleaseLock($fileResource);
                $this->sessionId = null;
                return;
            }

            $this->sessionId = $this->session->updated_token;
            $this->sessionFileResource = $this->openSessionLock($this->sessionId);
            $this->closeFileAndReleaseLock($fileResource); // release lock of expired session after obtaining lock for new session
            $this->setClientSessionId();

            if($this->request->getRoute()->getRequestMethod() === 'GET')
                $this->terminate();

            return;
        }

        // If we've made it here, then the token has expired, and has not been previously re-issued while still being
        // within it's liminal state. We must check to verify that the token is still valid for re-issuance (based off
        // of inactivity). If it is not, we set treat the request as though no session token was provided...
        if((time() - $this->session->last_used) > $this->session->allowed_inactivity)
        {
            // not valid for re-issuance
            $this->session = null;
            $this->closeFileAndReleaseLock($fileResource);
            $this->sessionId = null;
            return;
        }

        // ...if it is, we generate a new token and copy the data from this session into the new
        // session, then take the new sessionId and save it as the updated_token property within the old session,
        // update the old session's regenerated_at property, then set the new token to be issued to the client in
        // response
        $newToken = $this->generateNewSession();

        $account_id = $this->session->account_id;
        $account_type = $this->session->account_type;
        $flash_data = $this->session->flash_data;
        $data = $this->session->data;

        $this->session->reissued_at = time();
        $this->session->updated_token = $newToken;

        $this->writeSessionToFile($fileResource);

        // open lock on new session token
        $this->sessionId = $newToken;
        $this->sessionFileResource = $this->openSessionLock($this->sessionId);
        // update required parameters
        $this->session->account_id = $account_id;
        $this->session->account_type = $account_type;
        $this->session->flash_data = $flash_data;
        $this->session->data = $data;
        // write updated session to file
        $this->writeSessionToFile($this->sessionFileResource);
        // release lock on old session token
        $this->closeFileAndReleaseLock($fileResource);
        // attach new token to response
        $this->setClientSessionId();

        if($this->request->getRoute()->getRequestMethod() === 'GET')
            $this->terminate();
    }

    private function openSessionLock(string $token): mixed// @var resource
    {
        $filename = $this->config->directory . '/' . $token;

        $fileResource = fopen($filename, 'r+'); // Open the file for reading and writing (at this point file should already exist)

        flock($fileResource, LOCK_EX); // Attempt to acquire an exclusive lock, block and wait if one cannot be acquired
        clearstatcache(true, $filename); // Clear stat cache for the file
        $contents = fread($fileResource, filesize($filename)); // Read the entire file
        $this->session = json_decode($contents);

        return $fileResource;
    }

    private function closeFileAndReleaseLock(mixed $fileResource): void
    {
        flock($fileResource, LOCK_UN); // Release the lock
        fclose($fileResource); // Close the file
    }

    private function writeSessionToFile(mixed $fileResource): void
    {
        ftruncate($fileResource, 0); // Truncate file to zero length
        rewind($fileResource); // Rewind the file pointer
        fwrite($fileResource, json_encode($this->session)); // Write the new content
    }

    /**
     * Updates the session's last_used property, persists session to file data store, releases file lock
     *
     * @return void
     */
    public function terminate(): void
    {
        $this->session->last_used = time();
        $this->writeSessionToFile($this->sessionFileResource);
        $this->closeFileAndReleaseLock($this->sessionFileResource);
    }

    /**
     * Generates a new session token and corresponding filesystem datastore, then returns token. Does not
     * set token in response data, that needs to be handled by calling function if it is required
     *
     * @param int|null $accountId
     * @param string|null $accountType
     * @return string
     */
    private function generateNewSession(?int $accountId = null, ?string $accountType = null): string
    {
        // Generate the new signed session_id token
        $token = $this->securityUtility->generateSignedToken();

        $data = json_encode([
            'account_id' => $accountId,
            'account_type' => $accountType,
            'issued_at' => time(),
            'last_used' => time(),
            'valid_for' => $this->config->valid_for,
            'liminal_time' => $this->config->liminal_time,
            'allowed_inactivity' => $this->config->allowed_inactivity,
            'reissued_at' => null,
            'updated_token' => null,
            'csrf' => uuid_create_v4(),
            'flash_data' => [],
            'data' => []
        ]);

        // Create the file on the filesystem
        file_put_contents($this->config->directory . '/' . $token, $data);

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
    private function setClientSessionId(): void
    {
        // encrypt token for storage on client
        $encryptedToken = $this->securityUtility->encryptString($this->sessionId);

        if($this->request->getRoute()->getRouteType() === 'web')
        {
            setcookie($this->config->web_client_name, $encryptedToken, time() + $this->config->allowed_inactivity, '/');
        }
        elseif($this->request->getRoute()->getRouteType() === 'mobile')
        {
            header($this->config->mobile_client_name . ': ' . $encryptedToken);
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
     * @param string|null $accountType
     * @return void
     */
    public function startNewSession(?int $accountId = null, ?string $accountType = null): void
    {
        $this->sessionId = $this->generateNewSession($accountId, $accountType);
        $this->session = json_decode(file_get_contents($this->config->directory . '/' . $this->sessionId));
        $this->setClientSessionId();
    }

}