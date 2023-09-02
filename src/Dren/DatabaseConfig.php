<?php

namespace Dren;

class DatabaseConfig
{
    public string $host;
    public string $user;
    public string $pass;
    public string $db;

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