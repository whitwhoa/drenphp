<?php

namespace Dren;


class Request
{

    private $method;
    private $uri;
    private $cookies;
    private $getData;
    private $postData;
    private $referrer;


    public function __construct()
    {
        $this->setMethod();
        $this->setURI();
        $this->setGetData();
        $this->setCookies();
        $this->setPostData();
        $this->setReferrer();
    }

    public function getMethod() : string
    {
        return $this->method;
    }

    public function getURI() : string
    {
        return $this->uri;
    }

    public function getGetData() : object
    {
        return $this->getData;
    }

    public function getPostData() : object
    {
        return $this->postData;
    }

    public function getCookie(string $name) : ?string
    {
        if(isset($this->cookies[$name]))
            return $this->cookies[$name];

        return null;
    }

    public function getReferrer() : string
    {
        return $this->referrer;
    }

    private function setReferrer() : void
    {
        $this->referrer = $_SERVER['HTTP_REFERER'] ?? '';
    }

    private function setCookies() : void
    {
        $this->cookies = $_COOKIE;
    }

    private function setMethod() : void
    {
        $this->method = $_SERVER['REQUEST_METHOD'] ?? NULL;
    }

    private function setURI() : void
    {
        $this->uri = isset($_SERVER['REQUEST_URI']) ? strtok($_SERVER["REQUEST_URI"],'?') : NULL;
    }

    private function setGetData() : void
    {
        $this->getData = isset($_GET) ? (object)$_GET : NULL;
    }

    private function setPostData() : void
    {
        $this->postData = isset($_POST) ? (object)$_POST : NULL;
    }

}