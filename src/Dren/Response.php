<?php


namespace Dren;

use Dren\App;

class Response
{


    private $code;
    private $body;
    private $redirect;
    private $type;

    public function __construct()
    {

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

    public function json(array $body, int $code = 200) : Response
    {
        $this->code = $code;
        $this->type = 'application/json';
        $this->body = json_encode($body);
        return $this;
    }

    public function send() : void
    {

        if(App::$config->session->enabled){
            App::$sm->persist();
        }

        if($this->redirect){
            http_response_code(302);
            header('Location: ' . $this->redirect);
        }

        http_response_code($this->code);
        header('Content-Type: ' . $this->type);
        echo $this->body;
        //exit;

    }




}