<?php

namespace Dren\Model\DAOs;



use Exception;

class JobDAO extends \Dren\DAO
{
    /**
     * @throws Exception
     */
    public function updateJobExecution(int $executionId, string $endTime, string $status, string $exitCode) : void
    {
        $this->db->query("UPDATE job_executions SET end_time = ?, status = ?, exit_code = ? WHERE id = ?", [
            $endTime,
            $status,
            $exitCode,
            $executionId
        ])->exec();
    }
}