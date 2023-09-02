<?php
declare(strict_types=1);

namespace Dren;

use Dren\Exceptions\NotFound;
use Exception;

class Router
{
    private static ?Router $instance = null;

    private static function init() : void
    {
        if (self::$instance == null)
            self::$instance = new Router();
    }

    /** @var array<Route> */
    private array $routes;

    private ?int $activeRoute;

    private function __construct()
    {
        $this->routes = [];
        $this->activeRoute = null;
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

    /**
     * @throws NotFound
     */
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
     * @param string $uriString
     * @return $this
     * @throws Exception
     */
    public function get(string $uriString) : Router
    {
        $route = end($this->routes);
        if($route === false)
            throw new Exception("Unable to set array pointer to last element");

        $route->setRequestMethod('GET');
        $route->setUri($uriString);

        return $this;
    }

    /**
     * @param string $uriString
     * @return $this
     * @throws Exception
     */
    public function post(string $uriString) : Router
    {
        $route = end($this->routes);
        if($route === false)
            throw new Exception("Unable to set array pointer to last element");

        $route->setRequestMethod('POST');
        $route->setUri($uriString);
        $route->setBlocking(true);

        return $this;
    }

    /**
     * @return $this
     * @throws Exception
     */
    public function block(): Router
    {
        $route = end($this->routes);
        if($route === false)
            throw new Exception("Unable to set array pointer to last element");

        $route->setBlocking(true);

        return $this;
    }

    /**
     * @param string $controllerString
     * @return $this
     * @throws Exception
     */
    public function controller(string $controllerString) : Router
    {
        $route = end($this->routes);
        if($route === false)
            throw new Exception("Unable to set array pointer to last element");

        $route->setController($controllerString);

        return $this;
    }

    /**
     * @param string $methodString
     * @return $this
     * @throws Exception
     */
    public function method(string $methodString) : Router
    {
        $route = end($this->routes);
        if($route === false)
            throw new Exception("Unable to set array pointer to last element");

        $route->setMethod($methodString);

        return $this;
    }

    /**
     * @param array<string> $middleware
     * @return $this
     * @throws Exception
     */
    public function middleware(array $middleware) : Router
    {
        $route = end($this->routes);
        if($route === false)
            throw new Exception("Unable to set array pointer to last element");

        $route->setMiddleware($middleware);

        return $this;
    }

    /**
     * @param string $formDataValidatorString
     * @return $this
     * @throws Exception
     */
    public function formDataValidator(string $formDataValidatorString) : Router
    {
        $route = end($this->routes);
        if($route === false)
            throw new Exception("Unable to set array pointer to last element");

        $route->setFormDataValidator($formDataValidatorString);

        return $this;
    }

}