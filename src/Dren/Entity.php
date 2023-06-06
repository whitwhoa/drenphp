<?php

namespace Dren;


class Entity
{
    protected $hasDbCon = true;
    protected $dbName = null;
    protected $table = null;
    protected $columns = [];

    protected $db = null;


    /**
     * Model constructor.
     *
     * @throws \Exception
     */
    public function __construct()
    {
        if($this->hasDbCon && !$this->table)
            throw new \Exception('Model that uses database connection MUST specify a table property');

        if($this->hasDbCon)
            $this->db = App::get()->getDb($this->dbName);
    }

    /**
     * Obtain a single row from the $table property containing an `id` column value of $id
     *
     * @param int $id
     */
    public function find(int $id) : void
    {
        if(!$this->hasDbCon)
            return;

        $result = $this->db->query("SELECT * FROM " . $this->table . " WHERE id = ?", [$id])->singleAsObj()->exec();
        if($result)
            foreach($result as $k => $v)
                $this->$k = $v;
    }

    /**
     * If id parameter exists, obtain the existing record as stdClass and only
     * update the parameters currently existing on the object.
     *
     * If id parameter does not exist, create a new record using the parameters
     * currently existing on the object
     *
     * @throws \Exception
     */
    public function save() : void
    {
        if(!$this->hasDbCon)
            return;

        if(isset($this->id))
            $this->updateExistingRecord();
        else
            $this->insertNewRecord();
    }

    /**
     *
     */
    private function updateExistingRecord() : void
    {
        $cols = [];
        $vals = [];
        foreach($this->columns as $k => $v)
        {
            if($k !== 'table')
            {
                $cols[] = $k;
                $vals[] = $v;
            }
        }

        $q = "UPDATE " . $this->table . " SET " . (function() use($cols){

            $colsStr = "";
            $cnt = 0;
            foreach($cols as $c)
            {
                $cnt++;
                $colsStr .= $c . " = ?";
                if($cnt !== count($cols))
                    $colsStr .= ", ";
            }
            return $colsStr;

        })() . " WHERE id = ?";

        $this->db->query($q, array_merge($vals, [$this->id]))->exec();
    }

    /**
     *
     *
     * @throws \Exception
     */
    private function insertNewRecord() : void
    {
        $cols = [];
        $vals = [];
        foreach($this->columns as $k => $v)
        {
            if($k !== 'table')
            {
                $cols[] = $k;
                $vals[] = $v;
            }
        }

        $q = "INSERT INTO " . $this->table . "(" .  implode(',', $cols) . ") VALUES(" . (function() use($cols){
            $qms = [];
            foreach($cols as $c)
                $qms[] = '?';
            return implode(',', $qms);
        })() . ")";

        $this->id = $this->db->query($q, $vals)->exec();
        if($this->id == 0)
        {
            throw new \Exception('Unable to insert new record! TF did you do?' .
                ' (You probably didn\'t include a necessary column...That\'s where I would put my money.)');
        }
    }

    /* The below PHP "MAGIC" methods are used in order to set columns property values equal to object properties. In
    *  short it turns:
     *  > THIS: $someEntity->param1 = 1 INTO $someEntity->columns['param1'] = 1
     *  > THIS: $someEntity->param1 INTO $someEntity['param1']
     * When the property name is not an actual predefined property name of the class. For further information look here:
     *  > https://secure.php.net/manual/en/language.oop5.magic.php
     *
     * In short: THIS PREVENTS OVERWRITING PREDEFINED PROPERTIES A USER MAY NOT BE AWARE OF
     *
     * */
    public function __set($key, $value)
    {
        $this->columns[$key] = $value;
    }

    public function __get($key)
    {
        return $this->columns[$key];
    }

    public function __isset($var)
    {
        if(isset($this->columns[$var])){
            return true;
        }
        return isset($this->$var);
    }

}