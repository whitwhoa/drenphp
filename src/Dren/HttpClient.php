<?php
declare(strict_types=1);

namespace Dren;

use Exception;

class HttpClient
{
    private string $url;
    private bool $verifySSL;
    private string $referer; // misspelled on purpose, if you know...you know
    private string $browserAgent;
    private string $cookiePath;
    private string $postType; // HTTP or JSON
    /** @var array<int|string, mixed>  */
    private array $postVars;
    private string $serverResponse;
    private ?int $httpStatus;
    private string $httpProxy;
    private string $headerOutput;
    private bool $acceptCookies;

    public function __construct(string $cookieStoragePath)
    {
        $this->url = '';
        $this->verifySSL = false;
        $this->referer = '';
        $this->browserAgent = '';
        $this->cookiePath = $cookieStoragePath;
        $this->postType = 'HTTP';
        $this->postVars = [];
        $this->serverResponse = '';
        $this->httpStatus = null;
        $this->httpProxy = '';
        $this->headerOutput = '';
        $this->acceptCookies = false;

    }

    /* PUBLIC SETTERS
    --------------------------------------------------------------------------*/
    /**
     * @throws Exception
     */
    public function setUrl(string $url) : HttpClient
    {
        if(!filter_var($url, FILTER_VALIDATE_URL))
            throw new Exception('cURL error: The given url is not valid');

        $this->url = $url;

        return $this;
    }

    public function setAcceptCookies(bool $b) : HttpClient
    {
        $this->acceptCookies = $b;

        return $this;
    }

    public function setVerifySSL(bool $verify) : HttpClient
    {
        if($verify)
            $this->verifySSL = true;
        else
            $this->verifySSL = false;

        return $this;
    }

    /**
     * @throws Exception
     */
    public function setReferer(string $url) : HttpClient
    {
        if(!filter_var($url, FILTER_VALIDATE_URL))
            throw new Exception('cURL error: The given referer url is not valid');

        $this->referer = $url;

        return $this;
    }

    public function setBrowserAgent(string $ba) : HttpClient
    {
        $this->browserAgent = $ba;

        return $this;
    }

    public function setCookiePath(string $path) : HttpClient
    {
        $this->cookiePath = $path;

        return $this;
    }

    public function setPostVar(mixed $name, mixed $value) : HttpClient
    {
        $this->postVars[$name] = $value;

        return $this;
    }

    public function setPostType(string $type) : HttpClient
    {
        $this->postType = $type;

        return $this;
    }

    public function setHttpProxy(string $pa) : HttpClient
    {
        $this->httpProxy = $pa;

        return $this;
    }


    /* PUBLIC GETTERS
    --------------------------------------------------------------------------*/
    public function getUrl() : string
    {
        return $this->url;
    }

    public function getVerifySSL(): bool
    {
        return $this->verifySSL;
    }

    public function getReferer(): string
    {
        return $this->referer;
    }

    public function getBrowserAgent(): string
    {
        return $this->browserAgent;
    }

    public function getCookiePath(): string
    {
        return $this->cookiePath;
    }

    /** @return array<int|string, mixed> */
    public function getPostVars(): array
    {
        return $this->postVars;
    }

    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function getResponse(): string
    {
        return $this->serverResponse;
    }

    public function getPostType(): string
    {
        return $this->postType;
    }

    public function getProxyAddress(): string
    {
        return $this->httpProxy;
    }

    public function getHeaderOutput(): string
    {
        return $this->headerOutput;
    }

    /* PUBLIC METHODS (NOT GETTERS OR SETTERS)
    --------------------------------------------------------------------------*/
    /**
     * @throws Exception
     */
    public function send() : HttpClient
    {
        $curl = curl_init();

        curl_setopt($curl, CURLOPT_URL, $this->url);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, $this->verifySSL);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLINFO_HEADER_OUT, true);

        if($this->referer != '')
            curl_setopt($curl, CURLOPT_REFERER, $this->referer);
        if($this->browserAgent != '')
            curl_setopt($curl, CURLOPT_USERAGENT, $this->browserAgent);
        if($this->acceptCookies && $this->cookiePath != '')
        {
            curl_setopt($curl, CURLOPT_COOKIEFILE, $this->cookiePath);
            curl_setopt($curl, CURLOPT_COOKIEJAR, $this->cookiePath);
        }
        if(!empty($this->postVars))
        {
            if($this->postType === 'HTTP')
            {
                curl_setopt($curl, CURLOPT_POST, 1);
                curl_setopt($curl, CURLOPT_POSTFIELDS, $this->postVars);
            }
            else if($this->postType === 'JSON')
            {
                $postDataAsJson = json_encode($this->postVars);

                if($postDataAsJson === false)
                    throw new Exception('Unable to encode json data');

                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
                curl_setopt($curl, CURLOPT_POSTFIELDS, $postDataAsJson);
                curl_setopt($curl, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen($postDataAsJson)
                ]);
            }
            else
            {
                throw new Exception('cURL error: The given post type is not supported');
            }
        }

        if($this->httpProxy !== '')
            curl_setopt($curl, CURLOPT_PROXY, $this->httpProxy);

        $curlExecResponse = curl_exec($curl);
        if($curlExecResponse === false)
            throw new Exception('Unable to execute request');

        /**
         * with CURLOPT_RETURNTRANSFER set to true curl_exec returns string on success, phpstan does not know this,
         * so we ignore the next line
         *
         * @phpstan-ignore-next-line
         */
        $this->serverResponse = $curlExecResponse;

        if(curl_error($curl))
            throw new Exception('cURL error: ' . curl_error($curl));

        $this->httpStatus = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlHeaderContent = curl_getinfo($curl, CURLINFO_HEADER_OUT);

        if(!$curlHeaderContent)
            $this->headerOutput = '';
        else
            $this->headerOutput = $curlHeaderContent;

        curl_close($curl);

        return $this;
    }


    /* END OF CLASS
    --------------------------------------------------------------------------*/
}