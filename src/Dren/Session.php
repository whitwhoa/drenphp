<?php

namespace Dren;

use Exception;

class Session
{
    private SessionConfig $sessionConfig;

    public ?int $accountId;

    /** @var array<string> */
    public array $accountRoles;

    public int $issuedAt;
    public int $lastUsed;
    public int $validFor;
    public int $liminalTime;
    public int $allowedInactivity;
    public ?int $reissuedAt;
    public ?string $updatedToken;
    public string $csrf;

    /** @var array<string, mixed>  */
    public array $flashData;

    /** @var array<string, mixed> */
    public array $data;

    private function __construct()
    {
        $this->sessionConfig = App::get()->getConfig()->session;
    }

    /**
     * @param int|null $accountId
     * @param array<string> $accountRoles
     * @return Session
     * @throws Exception
     */
    public static function generateNewSession(?int $accountId = null, array $accountRoles = []) : Session
    {
        $instance = new self();
        $instance->accountId = $accountId;
        $instance->accountRoles = $accountRoles;
        $instance->issuedAt = time();
        $instance->lastUsed = time();
        $instance->validFor = $instance->sessionConfig->valid_for;
        $instance->liminalTime = $instance->sessionConfig->liminal_time;
        $instance->allowedInactivity = $instance->sessionConfig->allowed_inactivity;
        $instance->reissuedAt = null;
        $instance->updatedToken = null;
        $instance->csrf = uuid_create_v4();
        $instance->flashData = [];
        $instance->data = [];

        return $instance;
    }

    /**
     * @param string $jsonString
     * @return Session
     * @throws Exception
     */
    public static function generateFromJson(string $jsonString) : Session
    {
        $data = json_decode($jsonString, true);
        if($data === false)
            throw new Exception("Unable to decode json string");

        $instance = new self();
        $instance->accountId = $data['accountId'];
        $instance->accountRoles = $data['accountRoles'];
        $instance->issuedAt = $data['issuedAt'];
        $instance->lastUsed = $data['lastUsed'];
        $instance->validFor = $data['validFor'];
        $instance->liminalTime = $data['liminalTime'];
        $instance->allowedInactivity = $data['allowedInactivity'];
        $instance->reissuedAt = $data['reissuedAt'];
        $instance->updatedToken = $data['updatedToken'];
        $instance->csrf = $data['csrf'];
        $instance->flashData = $data['flashData'];
        $instance->data = $data['data'];

        return $instance;
    }

    /**
     * @return string
     * @throws Exception
     */
    public function toJson() : string
    {
        $state = [
            'accountId' => $this->accountId,
            'accountRoles' => $this->accountRoles,
            'issuedAt' => $this->issuedAt,
            'lastUsed' => $this->lastUsed,
            'validFor' => $this->validFor,
            'liminalTime' => $this->liminalTime,
            'allowedInactivity' => $this->allowedInactivity,
            'reissuedAt' => $this->reissuedAt,
            'updatedToken' => $this->updatedToken,
            'csrf' => $this->csrf,
            'flashData' => $this->flashData,
            'data' => $this->data
        ];

        $encodedData = json_encode($state);
        if($encodedData === false)
            throw new Exception("Unable to encode session data");

        return $encodedData;
    }

}