<?php
declare(strict_types=1);

namespace Dren;


use Exception;

class Request
{

    private string $ip;
    private string $method;
    private string $uri;

    /** @var array<string, string>  */
    private array $cookies;
    private object $getData;
    private object $postData;
    private string $referrer;

    /** @var array<UploadedFile>  */
    private array $files;

    private ?Route $route;

    /** @var array<string, string>  */
    private array $headers;

    /** @var array<string, string> */
    private array $allowableMimes;

    /**
     * @param array<string, string> $am
     * @param string $ipParamName
     */
    public function __construct(array $am, string $ipParamName)
    {
        $this->allowableMimes = $am;

        $this->setIp($ipParamName);
        $this->setMethod();
        $this->setURI();
        $this->setGetData();
        $this->setCookies();
        $this->setPostData();
        $this->setReferrer();
        $this->setFiles();
        $this->setHeaders();
        $this->route = null; // null until value provided in App::execute()
    }

    public function getMethod() : string
    {
        return $this->method;
    }

    public function getURI() : string
    {
        return $this->uri;
    }

    public function getIp() : string
    {
        return $this->ip;
    }

    /**
     * called from App->execute() after call to Router::setActiveRoute()
     */
    public function setRoute(Route $route) : void
    {
        $this->route = $route;
    }

    private function setHeaders() : void
    {
        $this->headers = [];
        foreach ($_SERVER as $key => $value)
        {
            if (str_starts_with($key, 'HTTP_'))
            {
                $header = str_replace(' ', '-', ucwords(str_replace('_', ' ', substr($key, 5))));
                $this->headers[$header] = $value;
            }
        }
    }

    public function getHeader(string $name) : ?string
    {
        if(isset($this->headers[$name]))
            return $this->headers[$name];

        return null;
    }

    public function expectsJson() : bool
    {
        $headers = getallheaders();
        if (isset($headers['Accept']))
            return str_contains($headers['Accept'], 'application/json');

        return false;
    }

    public function isAjax(): bool
    {
        return $this->getHeader('X-Requested-With') === 'XMLHttpRequest';
    }

    public function getRouteParam(string $paramName) : mixed
    {
        if(array_key_exists($paramName, $this->route->getUriParams()))
            return $this->route->getUriParams()[$paramName];
        else
            return null;
    }

    /**
     * !!!NOTE!!!
     * This merges GET and POST data, if any GET parameters are provided which have the same name as a POST parameter,
     * the POST parameter will overwrite the GET parameter. Insure GET and POST parameter names are unique.
     */
    public function getRequestData() : object
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

    public function getRoute() : Route
    {
        return $this->route;
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

    /**
     * @throws Exception
     */
    private function setURI() : void
    {
        if(!isset($_SERVER['REQUEST_URI']))
            throw new Exception("Request uri must be provided");

        $baseUri = strtok($_SERVER["REQUEST_URI"],'?');
        if($baseUri === false)
            throw new Exception("Unable to tokenize request uri");

        $this->uri = $baseUri;
    }

    private function setGetData() : void
    {
        //$this->getData = isset($_GET) ? (object)$_GET : NULL;
        $this->getData = (object)$_GET;
    }

    private function setPostData() : void
    {
        //$this->postData = isset($_POST) ? (object)$_POST : NULL;
        $this->postData = (object)$_POST;
    }

    private function setIp(string $ipParamName) : void
    {
        $this->ip = $_SERVER[$ipParamName];
    }

    private function setFiles() : void
    {
        $this->files = [];

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
     * @return array<UploadedFile>
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
     * @return array<string, UploadedFile|array<UploadedFile>>
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