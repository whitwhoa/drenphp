<?php
declare(strict_types=1);

namespace Dren;

class Service
{
    protected ?string $dbName;
    protected ?MySQLCon $db;

    public function __construct()
    {
        $this->dbName = null;
        $this->db = App::get()->getDb($this->dbName);
    }
}