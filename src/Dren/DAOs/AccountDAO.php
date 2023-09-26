<?php
declare(strict_types=1);

namespace Dren\DAOs;

use Dren\DAO;
use Exception;

class AccountDAO extends DAO
{
    /**
     * @throws Exception
     * @param array<string> $roles
     */
    public function createNewAccount(string $username, string $password, string $ip, array $roles = []) : int
    {
        try
        {
            $this->db->beginTransaction();

            // create `accounts` record
            $q1 = <<<EOT
                INSERT INTO accounts (username, password, last_active, last_ip) VALUES (?, ?, ?, ?)
            EOT;

            $newAccountId = $this->db
                ->query($q1, [$username, $password, date("Y-m-d H:i:s"), $ip])
                ->exec();

            if(count($roles) > 0)
            {
                // get the ids of each provided role
                $bindString = $this->db->generateBindStringForArray($roles);
                $q2 = <<<EOT
                    SELECT JSON_ARRAYAGG(roles.id) AS ids FROM roles WHERE roles.role IN ($bindString)
                EOT;

                $roleIds = $this->db
                    ->query($q2, $roles)
                    ->exec();

                foreach(json_decode($roleIds->ids) as $roleId)
                {
                    $q3 = <<<EOT
                        INSERT INTO account_role (account_id, role_id) VALUES (?, ?)
                    EOT;

                    $this->db
                        ->query($q3, [$newAccountId, $roleId])
                        ->exec();
                }
            }

            $this->db->commitTransaction();

            return $newAccountId;
        }
        catch(Exception $e)
        {
            $this->db->rollbackTransaction();

            throw new Exception($e->getMessage()); // handle further up the stack
        }
    }

    /**
     * @throws Exception
     */
    public function getAccountById(int $id) : ?object
    {
        $q = <<<EOT
            SELECT *
            FROM accounts
            WHERE accounts.id = ?
        EOT;

        return $this->db
            ->query($q, [$id])
            ->singleAsObj()
            ->exec();
    }

    /**
     * @throws Exception
     */
    public function getAccountByUsername(string $username) : ?object
    {
        $q = <<<EOT
            SELECT *
            FROM accounts
            WHERE accounts.username = ?
        EOT;

        return $this->db
            ->query($q, [$username])
            ->singleAsObj()
            ->exec();
    }

    /**
     * @throws Exception
     */
    public function addRole(int $accountId, string $role) : void
    {
        try
        {
            $this->db->beginTransaction();

            $roleId = $this->db
                ->query("SELECT roles.id FROM roles WHERE roles.role = ?", [$role])
                ->singleAsObj()
                ->exec();

            $this->db
                ->query("INSERT INTO account_role (account_id, role_id) VALUES (?, ?)", [$accountId, $roleId->id])
                ->exec();

            $this->db->commitTransaction();
        }
        catch(Exception $e)
        {
            $this->db->rollbackTransaction();

            throw new Exception($e->getMessage()); // handle further up the stack
        }
    }

    /**
     * @throws Exception
     */
    public function removeRole(int $accountId, string $role) : void
    {
        try
        {
            $this->db->beginTransaction();

            $roleId = $this->db
                ->query("SELECT roles.id FROM roles WHERE roles.role = ?", [$role])
                ->singleAsObj()
                ->exec();

            $this->db
                ->query("DELETE FROM account_role WHERE account_id = ? AND role_id = ?", [$accountId, $roleId->id])
                ->exec();

            $this->db->commitTransaction();
        }
        catch(Exception $e)
        {
            $this->db->rollbackTransaction();

            throw new Exception($e->getMessage()); // handle further up the stack
        }
    }

    /**
     * @param int $accountId
     * @return array<string>
     * @throws Exception
     */
    public function getRoles(int $accountId): array
    {
        $q = <<<EOT
            SELECT 
                roles.role
            FROM accounts
            JOIN account_role ON accounts.id = account_role.account_id
            JOIN roles ON account_role.role_id = roles.id
            WHERE accounts.id = ?;
        EOT;

        $resultSet = $this->db
            ->query($q, [$accountId])
            ->asObj()
            ->exec();

        $roles = [];
        foreach($resultSet as $r)
            $roles[] = $r->role;

        return $roles;
    }

    /**
     * @return \stdClass[] where {
     *      int $id,
     *      string $username
     * }
     *
     * @throws Exception
     */
    public function getAllAccounts() : array
    {
        return $this->db
            ->query("SELECT id, username FROM accounts")
            ->asObj()
            ->exec();
    }

}