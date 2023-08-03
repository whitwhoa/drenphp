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

    private function openFileAndLock(string $filename): mixed// @var resource
    {
        $fileResource = fopen($filename, 'r+'); // Open the file for reading and writing

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

    private function updateSession(): void
    {
        $fileResource = $this->openFileAndLock($this->config->directory . '/' . $this->sessionId);

        // content of session file data store loaded into memory as $this->session value
        // holding lock for $this->sessionId file

        // Has session token expired?
        if(!(($this->session->issued_at + $this->session->valid_for) >= time()))
        {
            // token still valid
            $this->sessionFileResource = $fileResource;

            // If GET request, then update the session's last_used property and release the lock
            if($this->request->getRoute()->getRequestMethod() === 'GET')
                $this->terminate();

            // If POST nothing further required as the lock will remain open throughout the request and terminated
            // right before sending the response
            return;
        }

        // If we have made it here, then the session token has expired

        // Is session token valid for re-issuance?
        if(!((time() - $this->session->last_used) <= $this->session->allowed_inactivity))
        {
            // Token is not valid for re-issuance
            $this->session_id = null;
            return;
        }

        // Check if token is in liminal state

    }

    /**
     * Updates the session's last_used property, persists session to file data store, releases file lock
     *
     * @return void
     */
    public function terminate(): void
    {
        $this->session->last_used = time();
        ftruncate($this->sessionFileResource, 0); // Truncate file to zero length
        rewind($this->sessionFileResource); // Rewind the file pointer
        fwrite($this->sessionFileResource, json_encode($this->session)); // Write the new content
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
    private function generateNewSession(?int $accountId = null,?string $accountType = null): string
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
            'csrf' => uuid_create_v4(),
            'flash_data' => []
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