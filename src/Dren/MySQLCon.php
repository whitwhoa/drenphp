<?php
declare(strict_types=1);

namespace Dren;


use Exception;
use PDO;
use PDOStatement;

class MySQLCon 
{
    private ?PDO $pdo;
    private ?string $query;

    /** @var array<mixed> */
    private array $bind;

    /** @var array<mixed> */
    private array $resultSet;

    private int $fetchStyle;
    private bool $single;
    private ?PDOStatement $pdoStatement;


    /**
     * @param array{string, string, string, string} $con
     */
    function __construct(array $con)
    {
        $this->pdo = new PDO('mysql:host=' . $con[0] . ';dbname=' . $con[3] . ';', $con[1], $con[2]);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

        $this->query = null;
        $this->bind = [];
        $this->resultSet = [];
        $this->fetchStyle = PDO::FETCH_ASSOC;
        $this->single = false;
        $this->pdoStatement = null;
    }

    function __destruct() 
    {
        // close connection
        // This sucks because for type strictness and high level static analysis we have to make $pdo nullable just so
        // we can clear it here, which in reality makes no difference because the script is going to end as this is destructed
        // anyway, but whatever, now we get to add a bunch of checks and exceptions everywhere that will never be useful
        // for the sake of "correctness"...idk why I'm complaining, I chose to implement extreme strictness...
        $this->pdo = null;
    }

    /**
     * @param string $queryIn
     * @param array<mixed> $bind
     * @return $this
     */
    public function query(string $queryIn, array $bind = []) : MySQLCon
    {
        $this->query = $queryIn;
        $this->bind = $bind;

        return $this;
    }

    /**
     *
     * @return MySQLCon
     */
    public function asArray() : MySQLCon
    {
        $this->fetchStyle = PDO::FETCH_ASSOC;
        $this->single = false;

        return $this;
    }

    /**
     *
     * @return MySQLCon
     */
    public function singleAsArray() : MySQLCon
    {
        $this->fetchStyle = PDO::FETCH_ASSOC;
        $this->single = true;

        return $this;
    }

    /**
     *
     * @return MySQLCon
     */
    public function asObj(): MySQLCon
    {
        $this->fetchStyle = PDO::FETCH_OBJ;
        $this->single = false;

        return $this;
    }

    /**
     *
     * @return MySQLCon
     */
    public function singleAsObj(): MySQLCon
    {
        $this->fetchStyle = PDO::FETCH_OBJ;
        $this->single = true;

        return $this;
    }

    /**
     * @return void
     * @throws Exception
     */
    public function beginTransaction(): void
    {
        if($this->pdo === null)
            throw new Exception("PDO object is null...this will never happen");

        $this->pdo->beginTransaction();
    }

    /**
     * @return void
     * @throws Exception
     */
    public function commitTransaction(): void
    {
        if($this->pdo === null)
            throw new Exception("PDO object is null...this will never happen");

        $this->pdo->commit();
    }

    /**
     * @return void
     * @throws Exception
     */
    public function rollbackTransaction(): void
    {
        if($this->pdo === null)
            throw new Exception("PDO object is null...this will never happen");

        $this->pdo->rollBack();
    }

    /**
     *
     * @throws Exception
     */
    public function exec() : mixed
    {
        if($this->query === null)
            throw new Exception("Query value cannot be null");

        $qt = strtolower(explode(' ', trim($this->query))[0]);

        if(!in_array($qt, ['select', 'insert', 'update', 'delete', 'with']))
            throw new Exception('Query not a select, insert, update, delete, or unable to parse query type');

        // not a fan of thick case statements, this is easier to read...come at me bro
        if($qt === 'select' || $qt === 'with')
        {
            $this->generateMysqlStatement();
            $this->generateMysqlResultset();

            if($this->single)
                return $this->resultSet[0] ?? NULL;

            return $this->resultSet;
        }
        elseif($qt === 'insert')
        {
            $this->generateMysqlStatement();

            if($this->pdo === null)
                throw new Exception("PDO object is null...this will never happen");

            // casting this as an int, since that's what we expect this value to always be. Especially since this function
            // only works with auto_increment columns, therefore, always being some form of int. The only issue that could
            // arise from this is if you're using an int value that exceeds the 2.whatever billion ceiling on int type size,
            // so that's something to keep in mind
            return (int)$this->pdo->lastInsertId();
        }
        elseif($qt === 'update' || $qt === 'delete')
        {
            $this->generateMysqlStatement();
            return null;
        }

        return null;
    }

    // useful when query utilizes IN()

    /**
     * @param array<mixed> $bindArray
     * @return string
     */
    public function generateBindStringForArray(array $bindArray = []): string
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
     * Convert a string representation of an integer to an integer, or return null if string is not an integer
     *
     * Useful when obtaining newly created ids of int type, as $this->_mysql->lastInsertId() returns strings
     *
     * @param string $str
     * @return int|null
     */
    public function stringToInt(string $str) : ?int
    {
        if (preg_match('/^-?\d+$/', $str))
            return (int)$str;

        return null;
    }

    /**
     * Automatically generate the prepared statement based on the variable types
     * provided in parameter $bindArray and execute
     *
     * @return void
     * @throws Exception
     */
    private function generateMysqlStatement(): void
    {
        if($this->pdo === null)
            throw new Exception("PDO object is null...this will never happen");

        if($this->query === null)
            throw new Exception("Query cannot be null");

        $this->pdoStatement = $this->pdo->prepare($this->query);

        if(count($this->bind) > 0)
        {
            for($i = 0; $i < count($this->bind); $i++)
            {
                $type = gettype($this->bind[$i]);

                if($type === 'integer')
                    $this->pdoStatement->bindParam(($i + 1), $this->bind[$i], PDO::PARAM_INT);
                elseif($type === 'NULL')
                    $this->pdoStatement->bindParam(($i + 1), $this->bind[$i], PDO::PARAM_NULL);
                else
                    // if not integer or null then default to string
                    // http://php.net/manual/en/pdo.constants.php
                    $this->pdoStatement->bindParam(($i + 1), $this->bind[$i], PDO::PARAM_STR);
            }
        }

        $this->pdoStatement->execute();
    }

    /**
     * Generate a result set
     *
     * @return void
     * @throws Exception
     */
    private function generateMysqlResultset(): void
    {
        if($this->pdoStatement === null)
            throw new Exception("PDOStatement cannot be null");

		$this->resultSet = $this->pdoStatement->fetchAll($this->fetchStyle);
    }


/* END OF CLASS
*******************************************************************************/
}