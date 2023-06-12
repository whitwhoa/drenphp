<?php

namespace Dren;

use Exception;

class HttpClient {

    private string $_url = '';
    private bool $_verify_ssl = false;
    private string $_referer = ''; // misspelled on purpose, if you know...you know
    private string $_browser_agent = '';
    private string $_cookie_path = '';
    private string $_post_type = 'HTTP'; // HTTP or JSON
    private array $_post_vars = [];
    private string $_server_response = '';
    private string $_http_status = '';
    private string $_http_proxy = '';
    private string $_header_output = '';

    private bool $_accept_cookies = false;

    public function __construct(string $dataPath)
    {
        $this->_cookie_path = $dataPath;
    }

    /* PUBLIC SETTERS
    --------------------------------------------------------------------------*/
    public function setUrl($url) : HttpClient
    {
        if(!filter_var($url, FILTER_VALIDATE_URL))
            throw new Exception('cURL error: The given url is not valid');

        $this->_url = $url;

        return $this;
    }

    public function setAcceptCookies(bool $b) : HttpClient
    {
        $this->_accept_cookies = $b;

        return $this;
    }

    public function setVerifySSL(bool $verify) : HttpClient
    {
        if($verify)
            $this->_verify_ssl = true;
        else
            $this->_verify_ssl = false;

        return $this;
    }

    public function setReferer(string $url) : HttpClient
    {
        if(!filter_var($url, FILTER_VALIDATE_URL))
            throw new Exception('cURL error: The given referer url is not valid');

        $this->_referer = $url;

        return $this;
    }

    public function setBrowserAgent(string $browser_agent) : HttpClient
    {
        $this->_browser_agent = $browser_agent;

        return $this;
    }

    public function setCookiePath(string $path) : HttpClient
    {
        $this->_cookie_path = $path;

        return $this;
    }

    public function setPostVar(mixed $name, mixed $value) : HttpClient
    {
        $this->_post_vars[$name] = $value;

        return $this;
    }

    public function setPostType(string $type) : HttpClient
    {
        $this->_post_type = $type;

        return $this;
    }

    public function setHttpProxy(string $proxy_address) : HttpClient
    {
        $this->_http_proxy = $proxy_address;

        return $this;
    }


    /* PUBLIC GETTERS
    --------------------------------------------------------------------------*/
    public function getUrl() : string
    {
        return $this->_url;
    }

    public function getVerifySSL(): bool
    {
        return $this->_verify_ssl;
    }

    public function getReferer(): string
    {
        return $this->_referer;
    }

    public function getBrowserAgent(): string
    {
        return $this->_browser_agent;
    }

    public function getCookiePath(): string
    {
        return $this->_cookie_path;
    }

    public function getPostVars(): array
    {
        return $this->_post_vars;
    }

    public function getHttpStatus(): string
    {
        return $this->_http_status;
    }

    public function getResponse(): string
    {
        return $this->_server_response;
    }

    public function getPostType(): string
    {
        return $this->_post_type;
    }

    public function getProxyAddress(): string
    {
        return $this->_http_proxy;
    }

    public function getHeaderOutput(): string
    {
        return $this->_header_output;
    }

    /* PUBLIC METHODS (NOT GETTERS OR SETTERS)
    --------------------------------------------------------------------------*/
    public function send() : HttpClient
    {
        $curl = curl_init();

        curl_setopt($curl, CURLOPT_URL, $this->_url);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, $this->_verify_ssl);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLINFO_HEADER_OUT, true);

        if($this->_referer != '')
            curl_setopt($curl, CURLOPT_REFERER, $this->_referer);
        if($this->_browser_agent != '')
            curl_setopt($curl, CURLOPT_USERAGENT, $this->_browser_agent);
        if($this->_accept_cookies && $this->_cookie_path != '')
        {
            curl_setopt($curl, CURLOPT_COOKIEFILE, $this->_cookie_path);
            curl_setopt($curl, CURLOPT_COOKIEJAR, $this->_cookie_path);
        }
        if(!empty($this->_post_vars))
        {
            if($this->_post_type === 'HTTP')
            {
                curl_setopt($curl, CURLOPT_POST, 1);
                curl_setopt($curl, CURLOPT_POSTFIELDS, $this->_post_vars);
            }
            else if($this->_post_type === 'JSON')
            {
                $post_data_as_json = json_encode($this->_post_vars);
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
                curl_setopt($curl, CURLOPT_POSTFIELDS, $post_data_as_json);
                curl_setopt($curl, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen($post_data_as_json)
                ]);
            }
            else
            {
                throw new Exception('cURL error: The given post type is not supported');
            }
        }

        if($this->_http_proxy !== '')
            curl_setopt($curl, CURLOPT_PROXY, $this->_http_proxy);

        $this->_server_response = curl_exec($curl);

        if(curl_error($curl))
            throw new Exception('cURL error: ' . curl_error($curl));

        $this->_http_status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $this->_header_output = curl_getinfo($curl, CURLINFO_HEADER_OUT);

        curl_close($curl);

        return $this;
    }


    /* END OF CLASS
    --------------------------------------------------------------------------*/
}