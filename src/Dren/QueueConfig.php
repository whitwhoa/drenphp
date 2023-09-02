<?php

namespace Dren;

class QueueConfig
{
    public bool $use_worker_queue;
    public int $queue_workers;
    public int $queue_worker_lifetime;
    public string $lockable_datastore_type;

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