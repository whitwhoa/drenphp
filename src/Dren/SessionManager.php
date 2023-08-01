<?php


namespace Dren;


use Exception;

class SessionManager
{
    private object $config;
    private ?Request $request;

    private ?string $session_id;
    private ?string $remember_id;

    private ?MySQLCon $db;

    private bool $session_id_lock_issued;
    private bool $remember_id_lock_issued;

    public function __construct(object $sessionConfig)
    {
        $this->config = $sessionConfig;
        $this->session_id = null;
        $this->remember_id = null;
        $this->request = null; // null here because we can't completely initialize until after we receive request
        $this->db = null;
        $this->session_id_lock_issued = false;
        $this->remember_id_lock_issued = false;
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

        if($this->remember_id !== null)
        {
            // authenticateViaRememberId()
            // return
        }

    }

    private function getTokensFromClient(): void
    {
        $unverified_session_id = null;
        $unverified_remember_id = null;

        if($this->request->getRoute()->getRouteType() === 'web')
        {
            if($this->request->getCookie('session_id'))
                $unverified_session_id = $this->decryptAndVerifyToken($this->request->getCookie('session_id'));

            if($this->request->getCookie('remember_id'))
                $unverified_remember_id = $this->decryptAndVerifyToken($this->request->getCookie('remember_id'));
        }
        elseif($this->request->getRoute()->getRouteType() === 'mobile')
        {
            if($this->request->getHeader('Session-Id'))
                $unverified_session_id = $this->decryptAndVerifyToken($this->request->getHeader('Session-Id'));

            if($this->request->getHeader('Remember-Id'))
                $unverified_remember_id = $this->decryptAndVerifyToken($this->request->getHeader('Remember-Id'));
        }

        // if session id was provided, but it's corresponding file has been removed from the system, set it to null
        if($unverified_session_id !== null && !file_exists($this->config->directory . '/' . $unverified_session_id))
            $unverified_session_id = null;

        // if remember id was provided, but there is no corresponding record in remember_ids table, set it to null
        if($unverified_remember_id !== null)
        {
            $result = $this->db
                ->query("SELECT * FROM remember_ids WHERE remember_id = ?", [$unverified_remember_id])
                ->singleAsObj()
                ->exec();

            if(!$result)
                $unverified_remember_id = null;
        }

        // At this point, if a session_id or remember_id token has been provided, it has been decrypted and its
        // signature has been verified. It has also been verified that the corresponding entry within its datastore
        // still exists.
        $this->session_id = $unverified_session_id;
        $this->remember_id = $unverified_remember_id;
    }

    private function decryptAndVerifyToken($encryptedTokenAndSignatureWithIv, $cipherMethod = 'AES-256-CBC', $tokenLength = 16): ?string
    {
        try
        {
            if($encryptedTokenAndSignatureWithIv === null || $encryptedTokenAndSignatureWithIv === '')
                return null;

            // Split the encrypted token and signature and the IV
            $parts = explode('::', base64_decode($encryptedTokenAndSignatureWithIv), 2);

            // If there aren't two parts, something's wrong, so return null
            if (count($parts) < 2)
                return null;

            list($encryptedTokenAndSignature, $iv) = $parts;

            // Decrypt the token and the signature
            $tokenAndSignature = openssl_decrypt($encryptedTokenAndSignature, $cipherMethod, $this->config->signature_secret, 0, $iv);

            if ($tokenAndSignature === false)
                return null; // Decryption failed, probably because the encrypted data was tampered with

            // Split the token and the signature
            $token = substr($tokenAndSignature, 0, $tokenLength);
            $receivedSignature = substr($tokenAndSignature, $tokenLength);

            // Generate the expected signature
            $expectedSignature = substr(hash_hmac('sha256', $token, $this->config->signature_secret), 0, $tokenLength);

            // Verify the signature
            if (hash_equals($expectedSignature, $receivedSignature))
                return $token; // Signature is valid, return the token
            else
                return null; // Signature is invalid
        }
        catch (Exception $e)
        {
            return null;
        }
    }

    private function updateSession(): void
    {

    }

    private function authenticateViaRememberId(): void
    {

    }





}