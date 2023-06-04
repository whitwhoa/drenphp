<?php


namespace Dren;


use Dren\Exceptions\NotFound;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use Dren\App;

class Router
{

    private $routes = [];
    private $privateDir;
    private $requestURI;

    private $routeFound = false;

    private $controllerClassName;
    private $controllerClassMethodName;

    private $middleware = [];

    private $requestValidator = '';

    /**
     * Router constructor.
     *
     * @throws NotFound
     */
    public function __construct($privateDir, $requestURI)
    {

        $this->privateDir = $privateDir;
        $this->requestURI = $requestURI;

        $this->generateRoutesFromFiles();
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

    private function mapURI(string $classAndMethod) : void
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

    /**
     * @throws NotFound
     */
    private function generateRoutesFromFiles() : void
    {

        // if cache/routes.php does not exist, create it. cache directory should be wiped every time
        // a deployment is performed
        if(!file_exists($this->privateDir . '/cache/routes.php'))
        {
            $dir = new RecursiveDirectoryIterator($this->privateDir . '/routes');

            foreach (new RecursiveIteratorIterator($dir) as $filename => $file) 
                if(strpos($filename, ".php"))
                    $this->routes = array_merge($this->routes, require_once $filename);

            file_put_contents($this->privateDir . '/cache/routes.php', serialize($this->routes));

        } 
        else 
        {
            $this->routes = unserialize(file_get_contents($this->privateDir . '/cache/routes.php'));
        }


        foreach($this->routes as $r)
        {
            if($r[0] === $this->requestURI)
            {
                $this->mapURI($r[1]);
                $this->mapMiddleware(isset($r[2]) ? $r[2] : []);
                $this->mapRequestValidator(isset($r[3]) ? $r[3] : '');

                return;
            }
        }

        throw new NotFound('Route not found');
    }
}