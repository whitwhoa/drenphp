<?php

namespace Dren;


class Route
{
    // we declare these as nullable as they are set in the Router class via method chaining, and we must return an
    // instance to be built via said chaining
    private ?string $routeType; // web, api, or mobile (could have used enum but don't want a ton of pointless files, and psr standards dictate that every little enum should be its own file)
    private ?string $requestMethod; // get or post (not supporting anything else, there's no NEED)
    private ?string $uri;
    private ?string $uriRegex;
    private array $uriParamPlaceholders;
    private array $uriParams;
    private ?string $controller;
    private ?string $method;
    private array $middleware;
    private ?string $formValidator;

    public function __construct()
    {

        $this->routeType = null;
        $this->requestMethod = null;
        $this->uri = null;
        $this->uriRegex = null;
        $this->uriParamPlaceholders = [];
        $this->uriParams = [];
        $this->controller = null;
        $this->method = null;
        $this->middleware = [];
        $this->formValidator = null;
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

    public function setUri(string $uri): void
    {
        $this->uri = $uri;
        $this->generateRegexPattern();
    }

    public function setController(string $controller): void
    {
        $this->controller = 'App\\Controllers\\' . $controller;
    }

    public function setMethod(string $method): void
    {
        $this->method = $method;
    }

    public function setMiddleware(array $middleware): void
    {
        foreach($middleware as $m)
        {
            $this->middleware[] = 'App\\Middleware\\' . $m;
        }
    }

    public function setFormValidator(string $formValidator): void
    {
        $this->formValidator = 'App\FormDataValidators\\' . $formValidator;
    }

    /*
    * Returns an array of [[placeholderName=>value]]. Don't call this until we've matched a route in Router
    * */
    public function setUriParams(): void
    {
        $uri = $this->uri;
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

    public function getUri(): ?string
    {
        return $this->uri;
    }

    public function getUriRegex(): ?string
    {
        return $this->uriRegex;
    }

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

    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    public function getFormValidator(): ?string
    {
        return $this->formValidator;
    }

    public function getUriParams(): array
    {
        return $this->uriParams;
    }

    // OTHER MEMBERS
    private function generateRegexPattern(): void
    {
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

