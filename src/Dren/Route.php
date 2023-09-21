<?php
declare(strict_types=1);

namespace Dren;


use Exception;

class Route
{
    // we declare these as nullable as they are set in the Router class via method chaining, and we must return an
    // instance to be built via said chaining
    private ?string $routeType; // web, api, or mobile (could have used enum but don't want a ton of pointless files, and psr standards dictate that every little enum should be its own file)
    private ?string $requestMethod; // get or post (not supporting anything else, there's no NEED)
    private bool $blocking;
    private ?string $uri;
    private ?string $uriRegex;
    /** @var array<array{int, string}>  */
    private array $uriParamPlaceholders;
    /** @var array<string, string> */
    private array $uriParams;
    private ?string $controller;
    private ?string $method;
    /** @var array<string>  */
    private array $middleware;
    private ?string $formDataValidator;

    public function __construct()
    {

        $this->routeType = null;
        $this->requestMethod = null;
        $this->blocking = false;
        $this->uri = null;
        $this->uriRegex = null;
        $this->uriParamPlaceholders = [];
        $this->uriParams = [];
        $this->controller = null;
        $this->method = null;
        $this->middleware = [];
        $this->formDataValidator = null;
    }

    // SETTERS
    public function setRouteType(string $routeType): void
    {
        $this->routeType = $routeType;
    }

    public function setRequestMethod(string $requestMethod): void
    {
        $this->requestMethod = $requestMethod;
    }

    public function setBlocking(bool $blocking): void
    {
        $this->blocking = $blocking;
    }

    public function setUri(string $uri): void
    {
        $this->uri = $uri;
        $this->generateRegexPattern();
    }

    public function setController(string $controller): void
    {
        $this->controller = 'App\\Http\\Controllers\\' . $controller;
    }

    public function setMethod(string $method): void
    {
        $this->method = $method;
    }

    /**
     * @param array<string> $middleware
     * @return void
     */
    public function appendMiddleware(array $middleware): void
    {
        foreach($middleware as $m)
        {
            $middlewareFullPath = 'App\\Http\\Middleware\\' . $m;
            if(!in_array($middlewareFullPath, $this->middleware))
                $this->middleware[] = $middlewareFullPath;
        }
    }

    /**
     * @param array<string> $middleware
     * @return void
     */
    public function prependMiddleware(array $middleware) : void
    {
        foreach(array_reverse($middleware) as $m)
        {
            $middlewareFullPath = 'App\\Http\\Middleware\\' . $m;
            if(!in_array($middlewareFullPath, $this->middleware))
                array_unshift($this->middleware, $middlewareFullPath);
        }
    }


    public function setFormDataValidator(string $formDataValidator): void
    {
        $this->formDataValidator = 'App\\Http\\FormDataValidators\\' . $formDataValidator;
    }

    /*
    * Called by router after a valid route has been identified
    * */
    public function setUriParams(string $requestUri): void
    {
        $uri = $requestUri;
        $placeholders = $this->uriParamPlaceholders;

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
            $this->uriParams[$ph[1]] = $components[$ph[0]];
    }

    // GETTERS
    public function getRouteType(): ?string
    {
        return $this->routeType;
    }

    public function getRequestMethod(): ?string
    {
        return $this->requestMethod;
    }

    public function isBlocking(): ?bool
    {
        return $this->blocking;
    }

    public function getUri(): ?string
    {
        return $this->uri;
    }

    public function getUriRegex(): ?string
    {
        return $this->uriRegex;
    }

    /**
     * @return array<array{int, string}>
     */
    public function getUriParamPlaceholders(): array
    {
        return $this->uriParamPlaceholders;
    }

    public function getController(): ?string
    {
        return $this->controller;
    }

    public function getMethod(): ?string
    {
        return $this->method;
    }

    /**
     * @return array<string>
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    /**
     * @return string|null
     */
    public function getFormDataValidator(): ?string
    {
        return $this->formDataValidator;
    }

    /**
     * @return array<string, string>
     */
    public function getUriParams(): array
    {
        return $this->uriParams;
    }

    /**
     * @return void
     * @throws Exception
     */
    private function generateRegexPattern(): void
    {
        if($this->uri === null)
            throw new Exception("URI cannot be null");

        $rawRoute = $this->uri;

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

        $this->uriRegex = '/^' . $pattern . '$/';
        $this->uriParamPlaceholders = $placeholders;
    }

}

