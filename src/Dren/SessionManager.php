<?php


namespace Dren;


use Exception;

class SessionManager
{
    private object $config;
    private SecurityUtility $securityUtility;
    private ?Request $request;
    private ?string $session_id;
    private ?string $remember_id;

    private ?MySQLCon $db;

    private bool $session_id_lock_issued;

    private ?object $session;

    public function __construct(object $sessionConfig, SecurityUtility $su)
    {
        $this->config = $sessionConfig;
        $this->securityUtility = $su;
        $this->session_id = null;
        $this->remember_id = null;
        $this->request = null; // null here because we can't completely initialize until after we receive request
        $this->db = null;
        $this->session_id_lock_issued = false;
        $this->session = null;
    }

    public function setDb(?MySQLCon $db): void
    {
        $this->db = $db;
    }

    public function init(Request $request)
    {
        $this->request = $request;

        $this->getTokensFromClient();

        if($this->session_id !== null)
        {
            // update session
            // return
        }
    }

    private function getTokensFromClient(): void
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
        $this->session_id = $unverified_session_id;
    }

    private function updateSession(): void
    {

    }

    private function authenticateViaRememberId(): void
    {

    }

    /**
     * Generates a new session token and corresponding filesystem datastore
     *
     * @param int|null $account_id
     * @param string|null $accountType
     * @return string
     */
    private function generateNewSession(?int $account_id = null,?string $accountType = null): string
    {
        // Generate the new signed session_id token
        $token = $this->securityUtility->generateSignedToken();

        $data = json_encode([
            'account_id' => $account_id,
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
        // received the token by sending another request...yeah, that sounds right...)

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
        $encryptedToken = $this->securityUtility->encryptString($this->session_id);

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
     * @param int|null $account_id
     * @param string|null $accountType
     * @return void
     */
    public function startNewSession(?int $account_id = null, ?string $accountType = null): void
    {
        $this->session_id = $this->generateNewSession($account_id, $accountType);
        $this->session = json_decode(file_get_contents($this->config->directory . '/' . $this->session_id));
        $this->setClientSessionId();
    }

    /**
     * Called
     *
     * @return void
     */
    public function persist(): void
    {

    }


}