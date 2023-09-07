<?php
declare(strict_types=1);


namespace Dren;

use Exception;

class Response
{
    private int $code;
    private string $body;
    private ?string $redirect;
    private string $type;

    public function __construct()
    {
        $this->code = 200;
        $this->redirect = null;
    }

    public function setCode(int $httpCode) : Response
    {
        $this->code = $httpCode;
        return $this;
    }

    public function redirect(string $redirect) : Response
    {
        $this->redirect = $redirect;
        return $this;
    }

    public function html(string $body) : Response
    {
        $this->type = 'text/html';
        $this->body = $body;
        return $this;
    }

    /**
     * @param array<mixed>|string $body
     * @return $this
     * @throws Exception
     */
    public function json(array|string $body) : Response
    {
        if(is_array($body))
        {
            $encodedBody = json_encode($body);
            if($encodedBody === false)
                throw new Exception("Unable to encode body data");

            $this->body = $encodedBody;
        }
        else
        {
            $this->body = $body;
        }


        $this->type = 'application/json';
        return $this;
    }

    /**
     * @throws Exception
     */
    public function send() : void
    {
        if($this->redirect)
        {
            http_response_code(302);
            header('Location: ' . $this->redirect);
            return;
        }

        http_response_code($this->code);
        header('Content-Type: ' . $this->type);
        echo $this->body;
    }

}