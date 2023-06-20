<?php


namespace Dren;


use Dren\Exceptions\NotFound;

class Router
{

    private array $routes = [];
    private string $privateDir;
    private string $requestURI;

    private $routeFound = false;

    private string $controllerClassName;
    private string $controllerClassMethodName;

    private array $middleware = [];

    private string $requestValidator = '';

    private bool $cacheRoutes = true;

    private array $routeParameters = [];

    /**
     *
     */
    public function __construct(string $privateDir, string $requestURI, bool $cacheRoutes)
    {

        $this->privateDir = $privateDir;
        $this->requestURI = $requestURI;
        $this->cacheRoutes = $cacheRoutes;

        //$this->generateRoutesFromFiles();
    }

    public function getControllerClassName() : string
    {
        return $this->controllerClassName;
    }

    public function getControllerClassMethodName() : string
    {
        return $this->controllerClassMethodName;
    }

    public function getMiddleware() : array
    {
        return $this->middleware;
    }

    public function getRequestValidator() : string
    {
        return $this->requestValidator;
    }

    private function mapController(string $classAndMethod) : void
    {
        $this->routeFound = true;
        $this->controllerClassName = 'App\Controllers\\' . explode('@', $classAndMethod)[0];
        $this->controllerClassMethodName = explode('@', $classAndMethod)[1];
    }

    private function mapMiddleware(array $middleware) : void
    {
        foreach($middleware as $m)
        {
            $this->middleware[] = 'App\Middleware\\' . $m;
        }
    }

    private function mapRequestValidator(string $validatorName) : void
    {
        if($validatorName !== '')
            $this->requestValidator = 'App\RequestValidators\\' . $validatorName;
        else 
            $this->requestValidator = '';
    }

//    private function generateRegexPattern(string $rawRoute) : string
//    {
//        // Convert route to regex pattern and replace {any_set_of_characters}
//        // with regex that matches any set of characters
//        $pattern = preg_quote($rawRoute, '/');
//        $pattern = preg_replace('/\\\{[^}]*\\\}/', '([^\/]+)', $pattern);
//        return '/^' . $pattern . '$/';
//    }

    public function generateRegexPattern(string $rawRoute) : array
    {
        $pattern = preg_quote($rawRoute, '/');

        $placeholders = [];

        // Split the route into its components
        $components = explode('/', $rawRoute);
        $pos = 0;

        // if not root '/', then remove the 0 index empty string
        if(count($components) > 1)
            array_shift($components);

        // if last index is empty string, remove (meaning route was defined with unnecessary trailing /)
        if(end($components) == '')
            array_pop($components);

        // Use preg_replace_callback instead of preg_replace
        $pattern = preg_replace_callback('/\\\{([^}]*)\\\}/', function ($matches) use (&$pos, &$placeholders, $components) {

            while (!preg_match('/' . $matches[0] . '/', $components[$pos]) && $pos < count($components))
                $pos++;

            $placeholders[] = [$pos, $matches[1]];
            $pos++;
            return '([^\/]+)';
        },
            $pattern
        );

        return ['/^' . $pattern . '$/', $placeholders];
    }

    private function setRouteParameters(string $uri, array $placeholders) : void
    {
        if(count($placeholders) == 0)
            return;

        $components = explode('/', $uri);

        // if not root '/', then remove the 0 index empty string
        if(count($components) > 1)
            array_shift($components);

        // if last index is empty string, remove (meaning route was defined with unnecessary trailing /)
        if(end($components) == '')
            array_pop($components);

        foreach($placeholders as $ph)
            $this->routeParameters[$ph[1]] = $components[$ph[0]];
    }

    public function getRouteParameters() : array
    {
        return $this->routeParameters;
    }

    /**
     * @throws NotFound
     */
    public function generateRoutes() : void
    {
        // if cache/routes.php does not exist, create it. cache directory should be wiped every time
        // a deployment is performed
        if(!$this->cacheRoutes || ($this->cacheRoutes && !file_exists($this->privateDir . '/cache/routes.php')))
        {
            $routes = require_once $this->privateDir . '/routes.php';

            foreach($routes as $k => $v)
                $routes[$k][0] = $this->generateRegexPattern($v[0]);

            $this->routes = $routes;

            if($this->cacheRoutes)
                file_put_contents($this->privateDir . '/cache/routes.php', serialize($this->routes));
        } 
        else 
        {
            $this->routes = unserialize(file_get_contents($this->privateDir . '/cache/routes.php'));
        }

        foreach($this->routes as $r)
        {

            if(preg_match($r[0][0], $this->requestURI))
            {

                $this->mapController($r[1]);
                $this->mapMiddleware(isset($r[2]) ? $r[2] : []);
                $this->mapRequestValidator(isset($r[3]) ? $r[3] : '');
                $this->setRouteParameters($this->requestURI, $r[0][1]);

                return;
            }
        }

        throw new NotFound('Route not found');
    }
}