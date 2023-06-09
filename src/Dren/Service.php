<?php

namespace Dren;

class Service
{
    protected ?string $dbName = null;
    protected ?MySQLCon $db = null;

    public function __construct()
    {
        $this->db = App::get()->getDb($this->dbName);
    }
}