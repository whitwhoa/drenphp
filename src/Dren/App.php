<?php
declare(strict_types=1);

namespace Dren;

use Dren\Configs\AppConfig;
use Dren\Services\AuthService;
use Exception;
use Dren\Exceptions\Forbidden;
use Dren\Exceptions\NotFound;
use Dren\Exceptions\Unauthorized;
use Dren\Exceptions\UnprocessableEntity;
use PDOException;

class App
{
    private static ?App $instance = null;
    private string $privateDir;
    private AppConfig $config;
    private SecurityUtility $securityUtility;
    private ?MysqlConnectionManager $dbConMan; // MySQLConnectionManager
    private ?Request $request;
    private ?SessionManager $sessionManager; // SessionManager
    private ?ViewCompiler $viewCompiler; // ViewCompiler
    private HttpClient $httpClient;
    private ?LockableDataStore $ipLock;
    //private ?LockableDataStore $ridLock;
    private string $authServiceClass;
    private ?AuthService $authService;

    /**
     * @throws Exception
     */
    public static function initHttp(string $privateDir): ?App
    {
        if (self::$instance == null)
            self::$instance = new App($privateDir, '/storage/system/logs/application.log');

        return self::$instance;
    }

    /**
     * @throws Exception
     */
    public static function initCli(string $privateDir) : ?App
    {
        if (self::$instance === null)
            self::$instance = new App($privateDir, '/storage/system/logs/job.log');

        return self::$instance;
    }

    /**
     * @throws Exception
     */
    public static function get() : App
    {
        if(self::$instance === null)
            throw new \Exception('Attempting to get App instance before it has been initialized');

        return self::$instance;
    }

    /**
     * Insure all members which can be accessed via singleton are initialized in the order in which they need to be
     * initialized and passed to any other requiring classes
     *
     * @param string $privateDir
     * @param string $logFile
     */
    private function __construct(string $privateDir, string $logFile)
    {
        $this->privateDir = $privateDir;
        $this->config = new AppConfig(require_once $privateDir . '/config.php');
        $this->config->private_dir = $this->privateDir;
        Logger::init($this->privateDir . $logFile);

        $this->securityUtility = new SecurityUtility($this->config->encryption_key);
        $this->request = null;
        $this->sessionManager = null;
        $this->viewCompiler = new ViewCompiler($this->privateDir);
        $this->httpClient = new HttpClient($this->privateDir . '/storage/system/httpclient');
        $this->dbConMan = null;
        if(isset($this->config->databases) && count($this->config->databases) > 0)
            $this->dbConMan = new MysqlConnectionManager($this->config->databases);

        $this->ipLock = null;
        $this->authServiceClass = AuthService::class;
        $this->authService = null;
    }

    public function setAuthServiceClass(string $authServiceClass) : void
    {
        $this->authServiceClass = $authServiceClass;
    }

    /**
     * @throws Exception
     */
    public function getAuthService() : AuthService
    {
        if($this->authService === null)
            throw new Exception('Attempting to get AuthService before it has been initialized');

        return $this->authService;
    }

    public function executeHttp() : void
    {
        try
        {
            $this->request = new Request($this->config->allowed_file_upload_mimes, $this->config->ip_param_name);
            $this->sessionManager = new SessionManager($this->config, $this->securityUtility);
            $this->viewCompiler = new ViewCompiler($this->privateDir, $this->sessionManager);

            // Do route lookup or throw not found exception
            Router::setActiveRoute($this->request->getURI(), $this->request->getMethod());

            // Give request an instance of the active route
            $this->request->setRoute(Router::getActiveRoute());

            // Process session
            $this->sessionManager->loadSession($this->request);

            // If we have a database connection, assume it is configured for authentication, and initialize the authService
            if(isset($this->config->databases) && count($this->config->databases) > 0)
            {
//                $this->authService = new AuthService($this->privateDir, $this->config, $this->request,
//                    $this->getDb(), $this->securityUtility, $this->sessionManager);

                /** @phpstan-ignore-next-line */
                $this->authService = new $this->authServiceClass($this->privateDir, $this->config, $this->request,
                    $this->getDb(), $this->securityUtility, $this->sessionManager);


            }

            $this->authService?->checkForRememberId();

            // TODO: If user is authenticated, and this is a blocking route, upgrade lock to user id lock? Or perhaps
            // we just don't worry about this for now?

            // If blocking route, but no session token provided, block on ip address, create a new session
            // token, proceed
            if(Router::getActiveRoute()->isBlocking() && !$this->sessionManager->getSessionId())
            {
                if($this->config->lockable_datastore_type === 'file')
                    $this->ipLock = new FileLockableDataStore($this->privateDir . '/storage/system/locks/ip');
                // TODO: add logic for additional LockableDataStores

                if($this->ipLock  === null)
                    throw new Exception("Unable to retrieve a ip lock");

                $this->ipLock->openLock($this->request->getIp());
                $this->ipLock->overwriteContents((string)time());

                $this->sessionManager->startNewSession();

            }

            // Execute each middleware. If the return type is Dren\Response, send the response
            foreach(Router::getActiveRoute()->getMiddleware() as $m)
            {
                /** @var Middleware $m */
                $middlewareResponse = (new $m())->handle();

                if(gettype($middlewareResponse) === 'object' && get_class($middlewareResponse) === 'Dren\Response')
                {
                    $middlewareResponse->send();

                    $this->finalizeRequest();

                    return;
                }
            }

            //Q: why are we not checking if the request is json above like we do below????
            //A: because those cases are dependent on the middleware logic, thus that condition needs to be checked
            //      within each specific middleware if it is required

            // Execute request validator. If provided and validate() returns false,
            // return a redirect or json response depending on the set failureResponseType
            /** @var FormDataValidator $fdv */
            $fdv = Router::getActiveRoute()->getFormDataValidator();
            if($fdv !== null)
            {
                $fdv = new $fdv($this->request, $this->sessionManager);

                if(!$fdv->validate())
                {
                    if($this->request->isAjax() || $this->request->expectsJson())
                    {
                        (new Response())->setCode(422)->json([
                            'message' => 'Unable to process request due to validation errors',
                            'errors' => $fdv->getErrors()->export()
                        ])->send();
                    }
                    else
                    {
                        $this->sessionManager->saveFlash('errors', $fdv->getErrors()->export());
                        $this->sessionManager->saveFlash('old', $this->request->getRequestData());

                        (new Response())->redirect($this->request->getReferrer())->send();
                    }

                    $this->finalizeRequest();

                    return;
                }
            }

            // Execute the given method for the given controller class and send its
            // response (as every controller method returns a Response object)
            $class = Router::getActiveRoute()->getController();
            $method = Router::getActiveRoute()->getMethod();
            (new $class())->$method()->send();

            $this->finalizeRequest();
        }
        catch(Forbidden|NotFound|Unauthorized|UnprocessableEntity $e)
        {
            if($this->request->isAjax() || $this->request->expectsJson())
            {
                $message = '';
                switch ($e->getCode())
                {
                    case 401:
                        $message = 'You are not authorized to access this resource';
                        break;
                    case 403:
                        $message = 'This resource is forbidden';
                        break;
                    case 404:
                        $message = 'Resource does not exist';
                        break;
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

            $this->finalizeRequest();
        }
        catch (Exception|PDOException $e)
        {
            Logger::error($e->getMessage() . ":" . $e->getTraceAsString());

            //if($this->request->expectsJson())
            if($this->request->isAjax() || $this->request->expectsJson())
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

            $this->finalizeRequest();
         }
    }

    /**
     * @throws Exception
     */
    private function finalizeRequest() : void
    {
        $this->sessionManager?->finalizeSessionState();
        $this->ipLock?->closeLock();

        // Session and Lock GC
        if($this->config->session->use_garbage_collector && (rand(1, 100) <= $this->config->session->gc_probability))
        {
            Logger::write("Running session garbage collection");
            GC::run();
        }
    }

    public function getPrivateDir() : string
    {
        return $this->privateDir;
    }

    public function getConfig() : AppConfig
    {
        return $this->config;
    }

    /**
     * @param string|null $dbName
     * @return MySQLCon
     * @throws Exception
     */
    public function getDb(?string $dbName = null) : MySQLCon
    {
        if($this->dbConMan === null)
            throw new Exception("Connection manager was not initialized");

        return $this->dbConMan->get($dbName);
    }

    /**
     * @return Request
     * @throws Exception
     */
    public function getRequest() : Request
    {
        if($this->request === null)
            throw new Exception("Request was not initialized");

        return $this->request;
    }

    /**
     * @return SessionManager
     * @throws Exception
     */
    public function getSessionManager() : SessionManager
    {
        if($this->sessionManager === null)
            throw new Exception("SessionManager was not initialized");

        return $this->sessionManager;
    }

    /**
     * @return ViewCompiler
     * @throws Exception
     */
    public function getViewCompiler() : ViewCompiler
    {
        if($this->viewCompiler === null)
            throw new Exception("ViewCompiler was not initialized");

        return $this->viewCompiler;
    }

    public function getHttpClient() : HttpClient
    {
        return $this->httpClient;
    }

    public function getSecurityUtility() : SecurityUtility
    {
        return $this->securityUtility;
    }

    public function getIpLock() : ?LockableDataStore
    {
        return $this->ipLock;
    }

}