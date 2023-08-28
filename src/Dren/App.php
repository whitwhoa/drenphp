<?php

namespace Dren;

use Exception;
use Dren\Exceptions\Forbidden;
use Dren\Exceptions\NotFound;
use Dren\Exceptions\Unauthorized;
use Dren\Exceptions\UnprocessableEntity;
use PDOException;

class App
{
    private static $instance = null;
    private string $privateDir;
    private object $config;
    private SecurityUtility $securityUtility;
    private ?MysqlConnectionManager $dbConMan; // MySQLConnectionManager
    private ?Request $request;
    private ?SessionManager $sessionManager; // SessionManager
    private ?ViewCompiler $viewCompiler; // ViewCompiler
    private HttpClient $httpClient;
    private ?LockableDataStore $ipLock;
    private ?LockableDataStore $ridLock;
    private ?RememberIdManager $rememberIdManager;

    /**
     * @throws Exception
     */
    public static function initHttp(string $privateDir): ?App
    {
        if (self::$instance == null)
        {
            self::$instance = new App($privateDir, '/storage/system/logs/application.log');
            self::$instance->_httpConstructor();
        }

        return self::$instance;
    }

    /**
     * @throws Exception
     */
    public static function initCli(string $privateDir): ?App
    {
        if (self::$instance == null)
            self::$instance = new App($privateDir, '/storage/system/logs/job.log');

        return self::$instance;
    }

    /**
     * @throws Exception
     */
    public static function get()
    {
        if(self::$instance == null)
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
        //TODO: I suppose we need a try catch here so we can display a super generic omg message to the user
        // since if something errors here, we can't proceed into the actual execution of the app where we would
        // be catching http error code exceptions and returning valid views based off of those...essentially...
        // if we throw exception here...really bad things have happened

        $this->privateDir = $privateDir;
        $this->config = (require_once $privateDir . '/config.php');
        Logger::init($this->privateDir . $logFile);

        $this->securityUtility = new SecurityUtility($this->config->encryption_key);
        $this->request = null;
        $this->sessionManager = null;
        $this->viewCompiler = null;
        $this->httpClient = new HttpClient($this->privateDir . '/storage/system/httpclient');
        $this->dbConMan = null;
        if(isset($this->config->databases) && count($this->config->databases) > 0)
            $this->dbConMan = new MysqlConnectionManager($this->config->databases);
        $this->ipLock = null;
        $this->ridLock = null;
        $this->rememberIdManager = null;
    }

    private function _httpConstructor() : void
    {
        $this->request = new Request($this->config->allowed_file_upload_mimes, $this->config->ip_param_name);
        $this->sessionManager = new SessionManager($this->config, $this->securityUtility);
        $this->viewCompiler = new ViewCompiler($this->privateDir, $this->sessionManager);

        if(isset($this->config->databases) && count($this->config->databases) > 0)
            $this->rememberIdManager = new RememberIdManager($this->config, $this->request, $this->getDb(), $this->securityUtility);
    }

    public function executeHttp() : void
    {
        try
        {
            // Do route lookup or throw not found exception
            Router::setActiveRoute($this->request->getURI(), $this->request->getMethod());

            // Give request an instance of the active route
            $this->request->setRoute(Router::getActiveRoute());

            // Process session
            $this->sessionManager->loadSession($this->request);

            if($this->rememberIdManager !== null)
            {
                // Check for remember_id token and attempt to re-authenticate the account if one is found.
                $this->rememberIdManager->setRememberId();

                if(!$this->sessionManager->getSessionId() && $this->rememberIdManager->hasRememberId())
                {
                    if($this->config->lockable_datastore_type === 'file')
                    {
                        $this->ridLock = new FileLockableDataStore($this->privateDir . '/storage/system/locks/rid');
                        $this->ridLock->openLock($this->rememberIdManager->getRememberId());
                        $this->ridLock->overwriteContents(time());
                    }
                    // TODO: add additional blocks for additional LockableDataStores

                    $existingToken = $this->rememberIdManager->getRememberIdSession();

                    if($existingToken !== null && $this->sessionManager->dataStoreExists($existingToken))
                    {
                        $this->sessionManager->useSessionId($existingToken);
                    }
                    else
                    {
                        $account = $this->rememberIdManager->getRememberIdAccount();
                        $this->sessionManager->startNewSession($account->account_id, $account->roles);
                        $this->rememberIdManager->associateSessionIdWithRememberId($this->sessionManager->getSessionId());
                    }

                    $this->ridLock->closeLock();
                }
            }

            // TODO: If user is authenticated, and this is a blocking route, upgrade lock to user id lock? Or perhaps
            // we just don't worry about this for now?

            // If blocking route, but no session token provided, block on ip address, create a new session
            // token, proceed
            if(Router::getActiveRoute()->isBlocking() && !$this->sessionManager->getSessionId())
            {
                if($this->config->lockable_datastore_type === 'file')
                {
                    $this->ipLock = new FileLockableDataStore($this->privateDir . '/storage/system/locks/ip');
                    $this->ipLock->openLock($this->request->getIp());
                    $this->ipLock->overwriteContents(time());

                    $this->sessionManager->startNewSession();
                }
            }

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
                $fdv = new $fdv($this->request, $this->sessionManager);

                if(!$fdv->validate())
                {
                    //if($this->request->expectsJson())
                    if($this->request->isAjax())
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

                    return;
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
            //if($this->request->expectsJson())
            if($this->request->isAjax())
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
        }
        catch (Exception|PDOException $e)
        {
            Logger::error($e->getMessage() . ":" . $e->getTraceAsString());

            //if($this->request->expectsJson())
            if($this->request->isAjax())
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
    }

    public function getPrivateDir() : string
    {
        return $this->privateDir;
    }

    public function getConfig() : object
    {
        return $this->config;
    }

    /**
     * @throws Exception
     */
    public function getDb($dbName = null) : MySQLCon
    {
        return $this->dbConMan->get($dbName);
    }

    public function getRequest() : Request
    {
        return $this->request;
    }

    public function getSessionManager() : ?SessionManager
    {
        return $this->sessionManager;
    }

    public function getViewCompiler() : ViewCompiler
    {
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

    public function getRidLock() : ?LockableDataStore
    {
        return $this->ridLock;
    }

    public function getRememberIdManager() : RememberIdManager
    {
        return $this->rememberIdManager;
    }

}