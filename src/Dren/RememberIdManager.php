<?php
declare(strict_types=1);

namespace Dren;

use Exception;
use stdClass;

class RememberIdManager
{
    private AppConfig $config;
    private MySQLCon $db;
    private SecurityUtility $securityUtility;
    private Request $request;
    private ?string $rememberId;


    public function __construct(AppConfig $appConfig, Request $request, MySQLCon $db, SecurityUtility $su)
    {
        $this->config = $appConfig;
        // We make this nullable because if we're running the framework without a database connection we'll never
        // make it to calling anything in this class that would require a connection...and if we do, we need to
        // puke at runtime because we shouldn't be
        $this->db = $db;
        $this->securityUtility = $su;
        $this->request = $request;
        $this->rememberId = null;
    }

    /**
     * @throws Exception
     */
    public function setRememberId() : void
    {
        $rid = null;
        if($this->request->getRoute()->getRouteType() === 'web')
        {
            if($this->request->getCookie($this->config->session->rid_web_client_name))
                $rid = $this->securityUtility->decryptAndVerifyToken($this->request->getCookie($this->config->session->rid_web_client_name));
        }
        elseif($this->request->getRoute()->getRouteType() === 'mobile')
        {
            if($this->request->getHeader($this->config->session->rid_mobile_client_name))
                $rid = $this->securityUtility->decryptAndVerifyToken($this->request->getHeader($this->config->session->rid_mobile_client_name));
        }

        // if provided token is valid, check if there's still an entry in the db
        if($rid)
        {
            // check database
            $resultSet = $this->db
                ->query('SELECT * FROM remember_ids WHERE remember_id = ?', [$rid])
                ->asObj()
                ->exec();

            if(count($resultSet) === 0)
                $rid = null;
        }

        $this->rememberId = $rid;
    }

    public function hasRememberId() : bool
    {
        if($this->rememberId !== null)
            return true;

        return false;
    }

    public function getRememberId() : ?string
    {
        return $this->rememberId;
    }

    public function getRememberIdSession() : ?string
    {
        $q = <<<EOT
            SELECT session_id FROM remember_id_sessions 
            WHERE remember_id = ?
            AND created_at >= CURRENT_TIMESTAMP - INTERVAL 1 MINUTE
        EOT;

        $result = $this->db
            ->query($q, [$this->rememberId])
            ->singleAsObj()
            ->exec();

        if(!$result)
            return null;

        return $result->session_id;
    }

    /**
     *
     * @return object{account_id: int, username: string, roles: array<string>}
     * @throws Exception
     */
    public function getRememberIdAccount() : object
    {
        $q = <<<EOT
            SELECT accounts_top_level.id as account_id, accounts_top_level.username,
            (
                SELECT
                    JSON_ARRAYAGG(
                    roles.role
                )
                FROM account_role
                JOIN roles ON account_role.role_id = roles.id
                WHERE account_role.account_id = accounts_top_level.id
            ) as roles
            FROM accounts as accounts_top_level
            JOIN remember_ids ON remember_ids.account_id = accounts_top_level.id
            WHERE remember_ids.remember_id = ?
        EOT;

        $result = $this->db
            ->query($q, [$this->rememberId])
            ->singleAsObj()
            ->exec();

        if(!$result)
            throw new Exception("Unable to retrieve account details using remember_id token");

        $result->roles = json_decode($result->roles);

        return $result;
    }

    public function associateSessionIdWithRememberId(string $sessionId) : void
    {
        $q = <<<EOT
            INSERT INTO remember_id_sessions (session_id, remember_id) VALUES (?, ?)
        EOT;

        $this->db
            ->query($q, [$sessionId, $this->rememberId])
            ->exec();
    }

    public function createNewRememberId(int $accountId) : void
    {
        $token = $this->securityUtility->generateSignedToken();

        $q = <<<EOT
            INSERT INTO remember_ids (account_id, remember_id) VALUES (?, ?)
        EOT;

        $this->db
            ->query($q, [$accountId, $token])
            ->exec();

        $encryptedToken = $this->securityUtility->encryptString($token);

        if($this->request->getRoute()->getRouteType() === 'web')
        {
            setcookie(
                $this->config->session->rid_web_client_name,
                $encryptedToken,
                [
                    'expires' => (int)strtotime("+1 year"),
                    'path' => '/',
                    'secure' => $this->config->session->cookie_secure,
                    'httponly' => $this->config->session->cookie_httponly,  // HttpOnly flag
                    'samesite' => $this->config->session->cookie_samesite  // SameSite attribute
                ]
            );
        }
        elseif($this->request->getRoute()->getRouteType() === 'mobile')
        {
            header($this->config->session->rid_mobile_client_name . ': ' . $encryptedToken);
        }
    }

    /**
     * @throws Exception
     */
    public function clearRememberId() : void
    {
        // remove database entries for remember_id token
        try
        {
            $this->db->beginTransaction();

            // delete from remember_id_sessions
            $q1 = <<<EOT
                DELETE FROM remember_id_sessions WHERE remember_id = ?
            EOT;

            $this->db
                ->query($q1, [$this->rememberId])
                ->exec();

            // delete from remember_ids
            $q2 = <<<EOT
                DELETE FROM remember_ids WHERE remember_id = ?
            EOT;

            $this->db
                ->query($q2, [$this->rememberId])
                ->exec();

            $this->db->commitTransaction();
        }
        catch(Exception $e)
        {
            $this->db->rollbackTransaction();

            throw new Exception($e->getMessage()); // handle further up the stack
        }

        // add parameters to response so client clears token
        $this->removeClientRememberId();
    }

    private function removeClientRememberId() : void
    {
        if($this->request->getRoute()->getRouteType() === 'web')
        {
            setcookie(
                $this->config->session->rid_web_client_name,
                '',
                [
                    'expires' => time() - 3600,
                    'path' => '/',
                    'secure' => $this->config->session->cookie_secure,
                    'httponly' => $this->config->session->cookie_httponly,  // HttpOnly flag
                    'samesite' => $this->config->session->cookie_samesite  // SameSite attribute
                ]
            );
        }
        elseif($this->request->getRoute()->getRouteType() === 'mobile')
        {
            header($this->config->session->rid_mobile_client_name . ': ' . 'REMOVE');
        }
    }

}