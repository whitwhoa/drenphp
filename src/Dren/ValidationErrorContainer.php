<?php

namespace Dren;

class ValidationErrorContainer
{
    private array $errors = [];

    public function add(string $key, string $message) : void
    {
        $this->errors[$key][] = $message;
    }

    public function get(string $key) : array
    {
        //TODO: check for dots and stars .*
    }

    public function all() : array
    {

    }

    public function has() : bool
    {

    }



}