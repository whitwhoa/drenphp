<?php


namespace Dren;


/**
 * Session manager
 *
 * Currently pretty basic. Do not do much to prevent session highjacking at the moment.
 *
 * @package Dren\Database
 */
class SessionManager
{
    private $config = null;
    private $token = null; // the session token or null if none given
    private $session = null; // the session data object
    private $flashed = null;


    public function __construct()
    {
        $this->config = App::$config->session;
        $this->token = App::$request->getCookie(App::$config->session->name);

        if(!$this->token){
            $this->startSession();
            return;
        }

        $this->loadSession();
    }


    /**
     * Destroy the existing session and regenerate a new one.
     *
     * 1.) Destroy the existing session
     *      a.) Remove from user's browser
     *      b.) Remove from persistence layer
     *      c.) Remove $this->token
     *      d.) If not $keepData then set $this->session = null
     *
     * 2.) Start a new session
     *
     *
     * @param bool $keepData
     * @param int|null $userId
     */
    public function regenerate(bool $keepData = false, int $userId = null) : void
    {
        setcookie($this->token, '', time() - 3600, '/');
        switch($this->config->type){
            case 'file':
            default:
                unlink($this->config->directory . '/' . $this->token);
        }
        $this->token = null;
        if(!$keepData){
            $this->session->data = null;
        }
        $this->startSession($userId);
    }

    /**
     *
     *
     * @return int|null
     */
    public function getUserId() : ?int
    {
       return $this->session->user_id;
    }

    /**
     * @param string $key
     * @return mixed
     */
    public function get(string $key)
    {
        if(isset($this->flashed->$key)){
            return $this->flashed->$key;
        }
        if(isset($this->session->data->$key)){
            return $this->session->data->$key;
        }
        return null;
    }

    /**
     * Save the given $data to $this->session->data->$key
     *
     *
     * @param string $key
     * @param mixed $data
     */
    public function save(string $key, $data) : void
    {
        $this->session->data->$key = $data;
    }

    /**
     * Save given $data to $this->session->flash->$key
     *
     * @param string $key
     * @param $data
     */
    public function flashSave(string $key, $data) : void
    {
        $this->session->flash->$key = $data;
    }

    /**
     * Save the data to $this->config->type
     */
    public function persist() : void
    {
        switch($this->config->type){
            case 'file':
            default:
                file_put_contents($this->config->directory . '/' . $this->token, json_encode($this->session));
                setcookie($this->config->name, $this->token, time() + $this->config->lifetime, '/'); // reset lifetime
        }
    }

    /**
     * Wipe flash data
     */
    private function clearFlash() : void
    {
        $this->session->flash = (object)[];
    }

    /**
     * Load $this->session data depending on which persistence engine is being used
     */
    private function loadSession() : void
    {
        switch($this->config->type){
            case 'file':
            default:
                $this->loadFileSession();
        }
    }

    /**
     * > if $this->token not an existing file, then the token is invalid, so we regenerate the session
     *
     * > if there is an existing file with a name equal to $this->token:
     *      > decode it's value into $this->session
     *      > update it's last_active property
     *      > set $this->flashed equal to the value of $this->session->flash, then wipe flash
     */
    private function loadFileSession() : void
    {
        if(!file_exists($this->config->directory . '/' . $this->token)){
            $this->regenerate();
            return;
        }
        $this->session = json_decode(file_get_contents($this->config->directory . '/' . $this->token));
        $this->session->last_active = time();
        $this->flashed = $this->session->flash;
        $this->clearFlash();
    }

    /**
     * > Generate a guid to use as the session token
     * > if $this->config->type === 'file' create a new file in $this->config->directory where
     *      the name of the file is equal to $this->token and content equal to json data
     * > call setcookie($this->config->name, $this->token) to send cookie during next response.
     * @param int|null $userId
     */
    private function startSession(int $userId = null) : void
    {
        $this->token = guidv4();

        $this->session = (object)[
            'created_at' => time(),
            'last_active' => time(),
            'user_id' => $userId,
            'flash' => (object)[],
            'data' => (!isset($this->session) ||
                !isset($this->session->data) ||
                $this->session->data === NULL) ?(object)[] : $this->session->data
        ];
    }

}