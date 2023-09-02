<?php
declare(strict_types=1);

namespace Dren;

class DAO
{
    protected ?string $dbName = null;
    protected MySQLCon $db;

    public function __construct()
    {
        $this->db = App::get()->getDb($this->dbName);
    }
}