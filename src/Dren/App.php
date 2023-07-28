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
        //$this->router = new Router($privateDir, $this->request->getURI(), $this->config->cache_routes);

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
        try
        {
            // Do route lookup or throw not found exception
            Router::setActiveRoute($this->request->getURI(), $this->request->getMethod());

            // Give request an instance of the active route
            $this->request->setRoute(Router::getActiveRoute());

            // Execute each middleware. If the return type is Dren\Response, send the response
            foreach(Router::getActiveRoute()->getMiddleware() as $m)
            {
                $middlewareResponse = (new $m())->handle();

                if(gettype($middlewareResponse) === 'object' && get_class($middlewareResponse) === 'Dren\Response')
                {
                    $middlewareResponse->send();
                    return;
                }
            }

            //Q: why are we not checking if the request is json above like we do below????
            //A: because those cases are dependent on the middleware logic, thus that condition needs to be checked
            //      within each specific middleware if it is required

            // Execute request validator. If provided and validate() returns false,
            // return a redirect or json response depending on the set failureResponseType
            $fdv = Router::getActiveRoute()->getFormDataValidator();
            if($fdv)
            {
                $fdv = new $fdv($this->request);

                if(!$fdv->validate())
                {
                    if($this->request->isJsonRequest())
                    {
                        (new Response())->setCode(422)->json([
                            'message' => 'Unable to process request due to validation errors',
                            'errors' => $fdv->getErrors()->export()
                        ])->send();
                        return;
                    }
                    else
                    {
                        $this->sessionManager->flashSave('errors', $fdv->getErrors()->export());
                        $this->sessionManager->flashSave('old', $this->request->getGetPostData());
                        (new Response())->redirect($this->request->getReferrer())->send();
                        return;
                    }
                }
            }

            // Execute the given method for the given controller class and send its
            // response (as every controller method returns a Response object)
            $class = Router::getActiveRoute()->getController();
            $method = Router::getActiveRoute()->getMethod();
            (new $class())->$method()->send();

        }
        catch(Forbidden|NotFound|Unauthorized|UnprocessableEntity $e)
        {
            if($this->request->isJsonRequest())
            {
                $message = '';
                switch ($e->getCode())
                {
                    case 401:
                        $message = 'You are not authorized to access this resource';
                    case 403:
                        $message = 'This resource is forbidden';
                    case 404:
                        $message = 'Resource does not exist';
                    case 422:
                        $message = 'Unable to process request given provided parameters';
                }

                (new Response())->setCode($e->getCode())->json([
                    'message' => $message
                ])->send();
            }
            else
            {
                (new Response())->html($this->viewCompiler->compile('errors.' . $e->getCode(),
                    ['detailedMessage' => $e->getMessage()]))->send();
            }
        }
        catch (Exception $e)
        {
            error_log($e->getMessage() . ":" . $e->getTraceAsString());

            if($this->request->isJsonRequest())
            {
                (new Response())->setCode(500)->json([
                    'message' => 'An unexpected error was encountered while processing your request.'
                ])->send();
            }
            else
            {
                (new Response())->setCode(500)->html($this->viewCompiler->compile('errors.500',
                    ['detailedMessage' => 'An unexpected error was encountered while processing your request.']
                ))->send();
            }
         }
// TODO:
//         finally
//         {
//             // clear any open locks that have been set by this process id, for this device or user,
                // and actually, you might not even do this here and just rely on the register_shutdown_function()
                // which we will need to implement
//         }
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