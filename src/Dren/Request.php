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

    private array $files = [];

    private array $routeParameters = [];

    private array $allowableMimes = [];

    public function __construct(array $am)
    {
        $this->allowableMimes = $am;

        $this->setMethod();
        $this->setURI();
        $this->setGetData();
        $this->setCookies();
        $this->setPostData();
        $this->setReferrer();
        $this->setFiles();
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

    function isJsonRequest() : bool
    {
        $headers = getallheaders();
        if (isset($headers['Accept']))
            return str_contains($headers['Accept'], 'application/json');

        return false;
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

    private function setFiles() : void
    {
        if(count($_FILES) == 0)
            return;

        foreach($_FILES as $key => $val)
        {
            if(!is_array($val['name']))
            {
                $this->files[] = new UploadedFile($this->allowableMimes, $key, $val['name'], $val['type'],
                    $val['tmp_name'], $val['error'], $val['size']);
                continue;
            }

            // if we've made it here, user has uploaded multiple files under one form element name
            for($i = 0; $i < count($val['name']); $i++)
            {
                $this->files[] = new UploadedFile($this->allowableMimes, $key, $val['name'][$i], $val['type'][$i],
                    $val['tmp_name'][$i], $val['error'][$i], $val['size'][$i]);
            }
        }

    }

    public function hasFile(string $name) : bool
    {
        foreach($this->files as $f)
            if($f->getFormName() == $name)
                return true;

        return false;
    }

    /**
     * Returns a single file object. If $name is given and multiple files exist with this formName, the first one
     * in $this->files will be returned. For returning multiple files, use $this->files()
     *
     * @param string $name
     * @return UploadedFile|null
     */
    public function file(string $name) : ?UploadedFile
    {
        if(!$this->hasFile($name))
            return null;

        foreach($this->files as $k => $v)
            if($v->getFormName() == $name)
                return $v;

        return null;
    }

    /**
     * Returns an array of UploadedFiles that share the same formName value, or an empty array
     *
     * @param string $name
     * @return array
     */
    public function groupedFiles(string $name) : array
    {
        $matchingFiles = [];
        foreach($this->files as $f)
            if($f->getFormName() == $name)
                $matchingFiles[] = $f;

        return $matchingFiles;
    }

    /**
     * Create an array where each key is the form name that was provided with the uploaded file, where files
     * sharing the same formName are in an array, and files with unique formNames simply have key as formName and
     * UploadedFile as value
     *
     * Used within request validator to put files in format that it can use for running validation methods
     *
     * @return array
     */
    public function allFilesByFormName() : array
    {
        $groupedFiles = [];
        foreach($this->files as $uf)
            $groupedFiles[$uf->getFormName()][] = $uf;

        $filteredGroupedFiles = [];
        foreach($groupedFiles as $k => $v)
            $filteredGroupedFiles[$k] = count($v) === 1 ? $v[0] : $v;

        return $filteredGroupedFiles;
    }

}