<?php

namespace Dren\Services;

interface AuthServiceInterface
{
    public function onSessionUpgrade(int $accountId, string $username, array $roles) : void;
    public function forgotPassword(string $username) : void;
    public function initiateVerificationProcess(string $username) : void;
    public function verifyAccount(string $token) : bool;
    public function getUsernameFromVerificationToken(string $resetToken) : ?string;
    public function createVerificationToken(string $username, string $token) : void;
    public function updatePassword(int $accountId, string $newPass) : void;
    public function verificationTokenExists(string $token) : bool;
    public function hasRememberId() : bool;
    public function checkForRememberId(): void;
    public function createAccount(string $username, string $password, ?string $ip, array $roles = []) : int;
    public function authenticate(string $username, string $password) : bool;
    public function upgradeSession(string $username, string $ip, bool $remember = false) : void;
    public function logout() : void;
}