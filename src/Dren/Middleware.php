<?php

namespace Dren;

class Middleware
{
    protected $request;
    protected $sessionManager;

    public function __construct()
    {
        $this->request = App::get()->getRequest();
        $this->sessionManager = App::get()->getSessionManager();
    }
}