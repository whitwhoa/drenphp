<?php


namespace Dren;


use Dren\MySQLCon;



/**
 * Database connection manager
 *
 *
 * @package Dren\Database
 */
class MysqlConnectionManager
{

    private $config = null; // available connections
    private $connections = [];


    public function __construct($databaseConfig)
    {
        $this->config = $databaseConfig;
    }

    /**
     *
     *
     * @param string|null $dbName
     * @return MySQLCon
     * @throws \Exception
     */
    public function get(string $dbName = null) : MySQLCon
    {
        if(!$dbName)
            return $this->genCon($this->config[0]['db']);
        
        return $this->genCon($dbName);
    }


    /**
     * Check to see if connection for the given $dbName already exists and if so, return that,
     * otherwise create a new connection and return it.
     *
     * @param string $db
     * @return MySQLCon
     * @throws \Exception
     */
    private function genCon(string $db) : MySQLCon
    {
        if(array_key_exists($db, $this->connections))
            return $this->connections[$db];

        $connectionArray = [];
        foreach($this->config as $con)
            if($con['db'] === $db)
                $connectionArray = [$con['host'], $con['user'], $con['pass'], $con['db']];

        if(count($connectionArray) == 0)
            throw new \Exception('Given database name does not exist within configuration file');

        $con = new MySQLCon($connectionArray);

        $this->connections[$db] = $con;

        return $con;
    }

}