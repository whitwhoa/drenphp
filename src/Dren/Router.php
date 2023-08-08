<?php

namespace Dren;

use Dren\Exceptions\NotFound;

class Router
{
    private static $instance = null;

    private static function init()
    {
        if (self::$instance == null)
            self::$instance = new Router();
    }

    private array $routes;

    private ?int $activeRoute;

    private function __construct()
    {
        $this->routes = [];
    }

    public static function web() : Router
    {
        self::init();

        $r = new Route();
        $r->setRouteType('web');

        self::$instance->routes[] = $r;

        return self::$instance;
    }

    public static function mobile() : Router
    {
        self::init();

        $r = new Route();
        $r->setRouteType('mobile');

        self::$instance->routes[] = $r;

        return self::$instance;
    }

    public static function api() : Router
    {
        self::init();

        $r = new Route();
        $r->setRouteType('api');

        self::$instance->routes[] = $r;

        return self::$instance;
    }

    public static function setActiveRoute(string $requestUri, string $requestMethod): void
    {
        self::init();

        $i = 0;
        foreach(self::$instance->routes as $r)
        {
           if(preg_match($r->getUriRegex(), $requestUri))
           {
               if($r->getRequestMethod() !== strtoupper($requestMethod))
                   throw new NotFound('Route not found. Incompatible request method provided.');

               $r->setUriParams($requestUri);
               self::$instance->activeRoute = $i;
               return;
           }

            $i++;
        }

        throw new NotFound('Route not found');
    }

    public static function getActiveRoute(): Route
    {
        return self::$instance->routes[self::$instance->activeRoute];
    }

    /**
     * Regular non-blocking GET request
     *
     * @param string $uriString
     * @return $this
     */
    public function get(string $uriString) : Router
    {
        $route = end($this->routes);
        $route->setRequestMethod('GET');
        $route->setBlocking(false);
        $route->setUri($uriString);

        return $this;
    }

    /**
     * GET request that will block the same as a POST request. Only use these when you
     * know you need them for something, which should be very rare if adhering to the
     * principle that GET requests should be idempotent
     *
     * @param string $uriString
     * @return $this
     */
    public function getAndBlock(string $uriString) : Router
    {
        $route = end($this->routes);
        $route->setRequestMethod('GET');
        $route->setBlocking(true);
        $route->setUri($uriString);

        return $this;
    }

    /**
     * POST request. Blocks by default.
     *
     * @param string $uriString
     * @return $this
     */
    public function post(string $uriString) : Router
    {
        $route = end($this->routes);
        $route->setRequestMethod('POST');
        $route->setBlocking(true);
        $route->setUri($uriString);

        return $this;
    }

    public function controller(string $controllerString) : Router
    {
        $route = end($this->routes);
        $route->setController($controllerString);

        return $this;
    }

    public function method(string $methodString) : Router
    {
        $route = end($this->routes);
        $route->setMethod($methodString);

        return $this;
    }

    public function middleware(array $middleware) : Router
    {
        $route = end($this->routes);
        $route->setMiddleware($middleware);

        return $this;
    }

    public function formDataValidator(string $formDataValidatorString) : Router
    {
        $route = end($this->routes);
        $route->setFormDataValidator($formDataValidatorString);

        return $this;
    }

}