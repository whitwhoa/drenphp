<?php

namespace Dren;

class Controller
{
    protected $request;
    protected $sessionManager;
    protected $viewCompiler;

    public function __construct()
    {
        // classes extending controller only ever called in App::execute() after initialization has completed, so
        // safe to use singleton here to prime these values
        $this->request = App::get()->getRequest();
        $this->sessionManager = App::get()->getSessionManager();
        $this->viewCompiler = App::get()->getViewCompiler();
    }
}