<?php

namespace Dren;

use Exception;
use Dren\Exceptions\Forbidden;
use Dren\Exceptions\NotFound;
use Dren\Exceptions\Unauthorized;
use Dren\Exceptions\UnprocessableEntity;

class App
{
    private static $instance = null;

    private string $privateDir;
    private object $config;
    private ?MysqlConnectionManager $db; // MySQLConnectionManager
    private Request $request;
    private ?SessionManager $sessionManager; // SessionManager
    private ViewCompiler $viewCompiler; // ViewCompiler
    private Router $router; // Router

    private HttpClient $httpClient;

    public static function init(string $privateDir)
    {
        if (self::$instance == null) 
            self::$instance = new App($privateDir);

        return self::$instance;
    }

    public static function get()
    {
        if(self::$instance == null)
            throw new \Exception('Attempting to get App instance before it has been initialized');

        return self::$instance;
    }

    private function __construct(string $privateDir)
    {
        $this->privateDir = $privateDir;
        $this->config = (require_once $privateDir . '/config.php');
        $this->injectPrivateDirIntoConfig();

        // Initialize request
        $this->request = new Request($this->config->allowed_file_upload_mimes);

        // Initialize session manager if enabled within config
        if($this->config->session->enabled)
        {
            if(!$this->config->session->name)
                $this->config->session->name = strtoupper($this->config->app_name) . '_SESSION';
            
            $this->sessionManager = new SessionManager($this->config->session, $this->request->getCookie($this->config->session->name));
        } 
        else 
        {
            $this->sessionManager = null;
        }

        // Initialize view compiler
        $this->viewCompiler = new ViewCompiler($privateDir, $this->sessionManager);

        // Initialize router
        $this->router = new Router($privateDir, $this->request->getURI(), $this->config->cache_routes);

        // Initialize HttpClient
        $this->httpClient = new HttpClient($privateDir . '/storage/httpclient');

        // Initialize database if provided within config
        if(isset($this->config->databases) && count($this->config->databases) > 0)
            $this->db = new MysqlConnectionManager($this->config->databases);
        else 
            $this->db = null;
    }    

    /**
     * Inject $this->privateDir into every location that it is required within $this->config
     */
    private function injectPrivateDirIntoConfig() : void
    {
        if(isset($this->config->session->directory))
            $this->config->session->directory = $this->privateDir . $this->config->session->directory;
    }

    public function execute() : void
    {
        try{

            // Load routes, either from files or cache file
            $this->router->generateRoutes();

            $this->request->setRouteParameters($this->router->getRouteParameters());

            // Execute each middleware. If the return type is Dren\Response, send the response
            foreach($this->router->getMiddleware() as $m)
            {
                $middlewareResponse = (new $m())->handle();

                if(gettype($middlewareResponse) === 'object' && get_class($middlewareResponse) === 'Dren\Response')
                {
                    $middlewareResponse->send();
                    return;
                }
            }

            // Execute request validator. If provided and validate() returns false,
            // return a redirect or json response depending on the set failureResponseType
            $rv = $this->router->getRequestValidator();
            if($rv !== '')
            {
                $rv = new $rv($this->request);

                if(!$rv->validate())
                {
                    if(!$this->request->isJsonRequest())
                    {
                        $this->sessionManager->flashSave('errors', $rv->getErrors()->export());
                        $this->sessionManager->flashSave('old', $this->request->getGetPostData());
                        (new Response())->setCode(422)->redirect($this->request->getReferrer())->send();
                        return;
                    }
                    else
                    {
                        // TODO: implement this
                        return;
                    }

                }
            }

            // Execute the given method for the given controller class and send its
            // response (as every controller method returns a Response object)
            $class = $this->router->getControllerClassName();
            $method = $this->router->getControllerClassMethodName();
            (new $class())->$method()->send();

        }
        catch(Forbidden|NotFound|Unauthorized|UnprocessableEntity $e)
        {
            if(!$this->request->isJsonRequest())
            {
                (new Response())->html($this->viewCompiler->compile('errors.' . $e->getCode(),
                    ['detailedMessage' => $e->getMessage()]))->send();
            }
            else
            {
                // TODO: implement this
            }
        }
        catch (Exception $e)
        {
            error_log($e->getMessage() . ":" . $e->getTraceAsString());

            if(!$this->request->isJsonRequest())
            {
                (new Response())->html($this->viewCompiler->compile('errors.500',
                    ['detailedMessage' => 'An unexpected error was encountered while processing your request.']
                ))->send();
            }
            else
            {
                // TODO: implement this
            }

         }

    }

    public function getPrivateDir() 
    {
        return $this->privateDir;
    }

    public function getConfig() 
    {
        return $this->config;
    }

    public function getDb($dbName = null)
    {
        return $this->db->get($dbName);
    }

    public function getRequest() 
    {
        return $this->request;
    }

    public function getSessionManager() 
    {
        return $this->sessionManager;
    }

    public function getViewCompiler() 
    {
        return $this->viewCompiler;
    }

    public function getRouter() 
    {
        return $this->router;
    }

    public function getHttpClient()
    {
        return $this->httpClient;
    }
}