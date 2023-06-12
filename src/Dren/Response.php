<?php


namespace Dren;

use Dren\App;

class Response
{


    private $code;
    private $body;
    private $redirect;
    private $type;
    private $sessionManager = null;

    public function __construct()
    {
        // classes extending controller only ever called in App::execute() after initialization has completed, so
        // safe to use singleton here to prime these values
        if(App::get()->getConfig()->session->enabled)
            $this->sessionManager = App::get()->getSessionManager();
    }

    public function redirect(string $redirect) : Response
    {
        $this->redirect = $redirect;
        return $this;
    }

    public function html(string $body, int $code = 200) : Response
    {
        $this->code = $code;
        $this->type = 'text/html';
        $this->body = $body;
        return $this;
    }

    public function json(string $body, int $code = 200) : Response
    {
        $this->code = $code;
        $this->type = 'application/json';
        $this->body = $body;
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
        }

        http_response_code($this->code);
        header('Content-Type: ' . $this->type);
        echo $this->body;
        //exit;
    }

}