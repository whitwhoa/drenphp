<?php
declare(strict_types=1);

namespace Dren\DAOs;



use Dren\DAO;
use Exception;

final class JobDAO extends DAO
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
     * @param int $processId
     * @param string $name
     * @param string $startTime
     * @param string $status
     * @param string|null $data
     * @return int|null
     * @throws Exception
     */
    public function createJobExecution(int $processId, string $name, string $startTime, string $status, ?string $data = NULL) : ?int
    {
        return $this->db->query("INSERT INTO job_executions(process_id, name, start_time, status, data) VALUES(?,?,?,?,?)", [
            $processId,
            $name,
            $startTime,
            $status,
            $data
        ])->exec();
    }

    /**
     * @param string $name
     * @param string $data
     * @param int $workerId
     * @return int|null
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
     * @param int $workerId
     * @return \stdClass[] where {
     *      int $id,
     *      string $name,
     *      string $data,
     *      int $worker_id,
     *      string $added_at
     * }
     * @throws Exception
     */
    public function getQueuedJobs(int $workerId) : array
    {
        return $this->db->query("SELECT * FROM job_queue WHERE worker_id = ?", [$workerId])
            ->asObj()
            ->exec();
    }

    /**
     * @param int $recordId
     * @return int|null
     * @throws Exception
     */
    public function deleteJobQueue(int $recordId) : ?int
    {
        return $this->db->query("DELETE FROM job_queue WHERE id = ?", [$recordId])->exec();
    }

}