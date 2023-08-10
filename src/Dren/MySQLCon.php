<?php

namespace Dren;


use Exception;
use PDO;
use PDOException;
use PDOStatement;

class MySQLCon 
{
    private ?PDO $_mysql;
    private ?string $_query;
    private array $_bind;
    private array $_resultset;
    private int $_fetch_style;
    private bool $_single;
    private ?PDOStatement $_statement;


    function __construct($con)
    {
        $this->_mysql = new PDO('mysql:host=' . $con[0] . ';dbname=' . $con[3] . ';', $con[1], $con[2]);
        $this->_mysql->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->_mysql->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

        $this->_query = null;
        $this->_bind = [];
        $this->_resultset = [];
        $this->_fetch_style = PDO::FETCH_ASSOC;
        $this->_single = false;
        $this->_statement = null;
    }

    function __destruct() 
    {
        // close connection
        $this->_mysql = null;
    }

    public function query(string $query, array $bind = [])
    {
        $this->_query = $query;
        $this->_bind = $bind;

        return $this;
    }

    /**
     *
     * @return MySQLCon
     */
    public function asArray() : MySQLCon
    {
        $this->_fetch_style = PDO::FETCH_ASSOC;
        $this->_single = false;

        return $this;
    }

    /**
     *
     * @return MySQLCon
     */
    public function singleAsArray() : MySQLCon
    {
        $this->_fetch_style = PDO::FETCH_ASSOC;
        $this->_single = true;

        return $this;
    }

    /**
     *
     * @return MySQLCon
     */
    public function asObj(): MySQLCon
    {
        $this->_fetch_style = PDO::FETCH_OBJ;
        $this->_single = false;

        return $this;
    }

    /**
     *
     * @return MySQLCon
     */
    public function singleAsObj(): MySQLCon
    {
        $this->_fetch_style = PDO::FETCH_OBJ;
        $this->_single = true;

        return $this;
    }

    public function beginTransaction(): void
    {
        $this->_mysql->beginTransaction();
    }

    public function commitTransaction(): void
    {
        $this->_mysql->commit();
    }

    public function rollbackTransaction(): void
    {
        $this->_mysql->rollBack();
    }

    /**
     *
     * @throws Exception
     */
    public function exec() : mixed
    {
        $qt = strtolower(explode(' ', trim($this->_query))[0]);

        if(!in_array($qt, ['select', 'insert', 'update', 'delete']))
            throw new Exception('Query not a select, insert, update, delete, or unable to parse query type');

        switch($qt)
        {
            case 'select':
                $this->_generate_mysql_statement();
                $this->_generate_mysql_resultset();

                if($this->_single)
                    return isset($this->_resultset[0]) ? $this->_resultset[0] : NULL;

                return $this->_resultset;

            case 'insert':
                $this->_generate_mysql_statement();
                return $this->_mysql->lastInsertId();

            case 'update':
            case 'delete':
                $this->_generate_mysql_statement();
                return null;
        }
    }

    // useful when query utilizes IN()
    public function generate_bind_string_for_array(array $bindArray = []): string
    {
        $returnString = '';
        $count = 1;
        foreach($bindArray as $val) 
        {
            $returnString .= '?';

            if($count != count($bindArray))
                $returnString .= ",";

            $count++;
        }

        return $returnString;
    }

    /**
     * Automatically generate the prepared statement based on the variable types
     * provided in parameter $bindArray and execute
     *
     * @return void
     */
    private function _generate_mysql_statement(): void
    {
        $this->_statement = $this->_mysql->prepare($this->_query);

        if(count($this->_bind) > 0)
        {
            for($i = 0; $i < count($this->_bind); $i++)
            {
                $type = gettype($this->_bind[$i]);

                if($type === 'integer')
                    $this->_statement->bindParam(($i + 1), $this->_bind[$i], PDO::PARAM_INT);
                elseif($type === 'NULL')
                    $this->_statement->bindParam(($i + 1), $this->_bind[$i], PDO::PARAM_NULL);
                else
                    // if not integer or null then default to string
                    // http://php.net/manual/en/pdo.constants.php
                    $this->_statement->bindParam(($i + 1), $this->_bind[$i], PDO::PARAM_STR);
            }
        }

        $this->_statement->execute();
    }

    /**
     * Generate a result set
     *
     * @return void
     */
    private function _generate_mysql_resultset(): void
    {
		$this->_resultset = $this->_statement->fetchAll($this->_fetch_style);
    }


/* END OF CLASS
*******************************************************************************/
}