<?php

namespace Dren\Configs;

class AppConfig
{
    public ?string $private_dir;
    public string $app_name;
    public bool $display_errors;
    public string $base_url;
    public string $encryption_key;
    public string $lockable_datastore_type;
    public string $jobs_lockable_datastore_type;
    public string $ip_param_name;
    public SessionConfig $session;
    public QueueConfig $queue;

    /** @var array<int, DatabaseConfig> */
    public array $databases;

    /** @var array<string, string>  */
    public array $allowed_file_upload_mimes;

    /** @var array<string, mixed>  */
    public array $user_defined;

    /** @param array<string, mixed> $untypedConfig */
    public function __construct(array $untypedConfig)
    {
        foreach($untypedConfig as $k => $v)
        {
            if($k === 'session')
            {
                $this->session = new SessionConfig($v);
                continue;
            }

            if($k === 'queue')
            {
                $this->queue = new QueueConfig($v);
                continue;
            }

            if($k === 'databases')
            {
                foreach($v as $dbConf)
                    $this->databases[] = new DatabaseConfig($dbConf);

                continue;
            }

            if(property_exists($this, $k))
                $this->{$k} = $v;

        }
    }


}