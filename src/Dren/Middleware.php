<?php
declare(strict_types=1);

namespace Dren;

class Middleware
{
    protected Request $request;
    protected SessionManager $sessionManager;

    public function __construct()
    {
        $this->request = App::get()->getRequest();
        $this->sessionManager = App::get()->getSessionManager();
    }
}