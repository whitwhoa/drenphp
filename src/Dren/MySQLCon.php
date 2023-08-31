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

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commitTransaction(): void
    {
        $this->pdo->commit();
    }

    public function rollbackTransaction(): void
    {
        $this->pdo->rollBack();
    }

    /**
     *
     * @throws Exception
     */
    public function exec() : mixed
    {
        $qt = strtolower(explode(' ', trim($this->query))[0]);

        if(!in_array($qt, ['select', 'insert', 'update', 'delete']))
            throw new Exception('Query not a select, insert, update, delete, or unable to parse query type');

        switch($qt)
        {
            case 'select':
                $this->generateMysqlStatement();
                $this->generateMysqlResultset();

                if($this->single)
                    return $this->resultSet[0] ?? NULL;

                return $this->resultSet;

            case 'insert':
                $this->generateMysqlStatement();
                return $this->pdo->lastInsertId();

            case 'update':
            case 'delete':
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
     */
    private function generateMysqlStatement(): void
    {
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
     */
    private function generateMysqlResultset(): void
    {
		$this->resultSet = $this->pdoStatement->fetchAll($this->fetchStyle);
    }


/* END OF CLASS
*******************************************************************************/
}