<?php

namespace Dren;


class App
{

    public static $privateDir;
    public static $config;
    public static $db; // MySQLConnectionManager
    public static $request;
    public static $sm; // SessionManager
    public static $vc; // ViewCompiler
    public static $router; // Router


    /**
     * AppState constructor. Set to private to disallow instantiation of instances
     */
    private function __construct()
    {
    }

    /**
     *
     *
     * @param string $privateDir
     * @throws Exceptions\NotFound
     */
    public static function initialize(string $privateDir) : void
    {
        self::$privateDir = $privateDir;
        self::$config = (require_once $privateDir . '/config.php');
        self::injectPrivateDirIntoConfig();

        // Initialize request
        self::$request = new Request();

        // Initialize session manager if enabled within config
        if(self::$config->session->enabled){
            self::setSessionName();
            self::$sm = new SessionManager();
        } else {
            self::$sm = null;
        }

        // Initialize view compiler
        self::$vc = new ViewCompiler();

        // Initialize router
        self::$router = new Router();

        // Initialize database if provided within config
        if(isset(self::$config->databases) && count(self::$config->databases) > 0){
            self::$db = new MysqlConnectionManager();
        } else {
            self::$db = null;
        }

    }

    /**
     * Inject self::$privateDir into every location that it is required within self::$config
     */
    private static function injectPrivateDirIntoConfig() : void
    {
        if(isset(self::$config->session) && isset(self::$config->session->directory)){
            self::$config->session->directory = self::$privateDir . self::$config->session->directory;
        }

    }

    /**
     * Set the session name to be used
     */
    private static function setSessionName() : void
    {
        self::$config->session->name = self::$config->session->name ?
            self::$config->session->name : strtoupper(self::$config->app_name) . '_SESSION';
    }

}