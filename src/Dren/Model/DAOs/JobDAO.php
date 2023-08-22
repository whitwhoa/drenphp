<?php

namespace Dren\Model\DAOs;



use Dren\DAO;
use Exception;

class JobDAO extends DAO
{
    /**
     * @throws Exception
     */
    public function updateJobExecution(int $executionId, string $endTime, string $status, string $exitCode, ?string $exitMessage) : void
    {
        $this->db->query("UPDATE job_executions SET end_time = ?, status = ?, exit_code = ? WHERE id = ?", [
            $endTime,
            $status,
            $exitCode,
            $executionId
        ])->exec();
    }

    /**
     *
     *
     * @param string $processId
     * @param string $name
     * @param string $startTime
     * @param string $status
     * @return int|null
     * @throws Exception
     */
    public function createJobExecution(string $processId, string $name, string $startTime, string $status) : ?int
    {
        // TODO: curious to see how this plays out when we enable strict types, as the pdo method lastInsertId returns
        // strings, thus there must be some sort of type juggling going on or something since this method returns a
        // nullable int
        return $this->db->query("INSERT INTO job_executions(process_id, name, start_time, status) VALUES(?,?,?,?)", [
            $processId,
            $name,
            $startTime,
            $status
        ])->exec();
    }

}