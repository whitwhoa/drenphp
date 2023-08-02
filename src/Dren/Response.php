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
        //TODO: We need to think about how we want to handle this
        // In SessionManager, GET requests will update the required session data, save to disc, then release lock
        // POST requests that contain a valid session_id need to update the required session data, keep lock open
        // for the duration of the request, then persist any modified session data and release the lock...ok, cool,
        // so how we going to do that...
        // We can have logic in SessionManager such that whenever we call a member that modifies the state of the session
        // from a GET request, that we throw an exception, this would keep anyone from accidentally modifying session
        // state in a GET request, and since every POST request will block all other requests, it should be safe to
        // persist the data at this point, but instead of calling a persist() member, lets call it something like
        // finalizeSessionState() which checks if we're doing a POST or GET and only executes if we're POSTing
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