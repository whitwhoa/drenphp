<?php


namespace Dren;

use Dren\App;

class Response
{
    private int $code;
    private string $body;
    private ?string $redirect = null;
    private string $type;
    private SessionManager|null $sessionManager = null;

    public function __construct()
    {
        // classes extending controller only ever called in App::execute() after initialization has completed, so
        // safe to use singleton here to prime these values
        if(App::get()->getConfig()->session->enabled)
            $this->sessionManager = App::get()->getSessionManager();

        // default status return code to 200
        $this->code = 200;
    }

    public function setCode(int $httpCode) : object
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

    public function json(array|string $body) : Response
    {
        if(is_array($body))
            $this->body = json_encode($body);
        else
            $this->body = $body;

        $this->type = 'application/json';
        return $this;
    }

    public function send() : void
    {
        if($this->sessionManager)
            $this->sessionManager->persist();

        if($this->redirect)
        {
            http_response_code(302);
            header('Location: ' . $this->redirect);
            exit;
        }

        http_response_code($this->code);
        header('Content-Type: ' . $this->type);
        echo $this->body;
    }

}