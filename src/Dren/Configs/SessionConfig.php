<?php

namespace Dren\Configs;

class SessionConfig
{
    public string $web_client_name;
    public string $mobile_client_name;
    public string $rid_web_client_name;
    public string $rid_mobile_client_name;
    public string $directory;
    public int $valid_for;
    public int $liminal_time;
    public int $allowed_inactivity;
    public bool $use_garbage_collector;
    public int $gc_probability;
    public int $gc_divisor;
    public bool $cookie_secure;
    public bool $cookie_httponly;

    /** @var 'Lax'|'lax'|'None'|'none'|'Strict'|'strict'  */
    public string $cookie_samesite;

    /** @param array<string, mixed> $untypedConfig */
    public function __construct(array $untypedConfig)
    {
        foreach($untypedConfig as $k => $v)
        {
            if(property_exists($this, $k))
                $this->{$k} = $v;
        }
    }
}