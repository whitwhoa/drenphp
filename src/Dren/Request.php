<?php

namespace Dren;


class Request
{

    private string $method;
    private string $uri;
    private array $cookies;
    private object $getData;
    private object $postData;
    private string $referrer;
    //private array $fileData;

    private array $routeParameters = [];

    public function __construct()
    {
        $this->setMethod();
        $this->setURI();
        $this->setGetData();
        $this->setCookies();
        $this->setPostData();
        $this->setReferrer();
        //$this->setFileData();
    }

    public function getMethod() : string
    {
        return $this->method;
    }

    public function getURI() : string
    {
        return $this->uri;
    }

    /**
     * called from App->execute()
     */
    public function setRouteParameters(array $routeParams) : void
    {
        $this->routeParameters = $routeParams;
    }

    public function getRouteParam(string $paramName) : mixed
    {
        if(array_key_exists($paramName, $this->routeParameters))
            return $this->routeParameters[$paramName];
        else
            return null;
    }

    /**
     * !!!NOTE!!!
     * This merges GET and POST data, if any GET parameters are provided which have the same name as a POST parameter,
     * the POST parameter will overwrite the GET parameter. Insure GET and POST parameter names are unique.
     */
    public function getGetPostData() : object
    {
        return (object)array_merge((array)$this->getData, (array)$this->postData);
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

//    public function getFileData() : array
//    {
//
//    }

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

//    private function setFileData() : void
//    {
//
//    }

}