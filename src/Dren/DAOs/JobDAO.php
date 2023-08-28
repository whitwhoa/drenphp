<?php

namespace Dren\DAOs;



use Dren\DAO;
use Exception;

class JobDAO extends DAO
{
    /**
     * @throws Exception
     */
    public function updateJobExecution(int $executionId, string $endTime, string $status, string $exitCode, ?string $exitMessage = null) : void
    {
        $this->db->query("UPDATE job_executions SET end_time = ?, status = ?, exit_code = ?, exit_message = ? WHERE id = ?", [
            $endTime,
            $status,
            $exitCode,
            $exitMessage,
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
    public function createJobExecution(string $processId, string $name, string $startTime, string $status, ?string $data = null) : ?int
    {
        // TODO: curious to see how this plays out when we enable strict types, as the pdo method lastInsertId returns
        // strings, thus there must be some sort of type juggling going on or something since this method returns a
        // nullable int
        return $this->db->query("INSERT INTO job_executions(process_id, name, start_time, status, data) VALUES(?,?,?,?,?)", [
            $processId,
            $name,
            $startTime,
            $status,
            $data
        ])->exec();
    }

    /**
     * @throws Exception
     */
    public function createJobQueue(string $name, string $data, int $workerId) : ?int
    {
        return $this->db->query("INSERT INTO job_queue(name, data, worker_id) VALUES(?,?,?)", [
            $name,
            $data,
            $workerId
        ])->exec();
    }

    /**
     * @throws Exception
     */
    public function getQueuedJobs(int $workerId) : array
    {
        return $this->db->query("SELECT * FROM job_queue WHERE worker_id = ?", [$workerId])
            ->asObj()
            ->exec();
    }

}