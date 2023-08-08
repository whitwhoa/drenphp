<?php

namespace Dren;

use Exception;

class SecurityUtility
{
    private string $encryptionKey;

    public function __construct(string $key)
    {
        $this->encryptionKey = $key;
    }

    public function decryptAndVerifyToken(?string $encryptedTokenAndSignatureWithIv, string $cipherMethod = 'AES-256-CBC', int $tokenLength = 16): ?string
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
            $tokenAndSignature = openssl_decrypt($encryptedTokenAndSignature, $cipherMethod, $this->encryptionKey, 0, $iv);

            if ($tokenAndSignature === false)
                return null; // Decryption failed, probably because the encrypted data was tampered with

            // Split the token and the signature
            $token = substr($tokenAndSignature, 0, $tokenLength);
            $receivedSignature = substr($tokenAndSignature, $tokenLength);

            // Generate the expected signature
            $expectedSignature = substr(hash_hmac('sha256', $token, $this->encryptionKey), 0, $tokenLength);

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

    public function generateSignedToken(string $cipherMethod = 'AES-256-CBC', int $tokenLength = 16): string
    {
        // Generate a random string of specified length
        $token = bin2hex(random_bytes($tokenLength / 2));

        // Generate a signature
        $signature = substr(hash_hmac('sha256', $token, $this->encryptionKey), 0, $tokenLength);

        // Combine the token and the signature
        return $token . $signature;
    }

    public function encryptString(string $data, string $cipherMethod = 'AES-256-CBC'): string
    {
        // Generate a random initialization vector
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length($cipherMethod));

        // Encrypt the data
        $encryptedData = openssl_encrypt($data, $cipherMethod, $this->encryptionKey, 0, $iv);

        // Combine the encrypted data and the IV
        return base64_encode($encryptedData . '::' . $iv);
    }

}