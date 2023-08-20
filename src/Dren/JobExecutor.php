<?php

namespace Dren;

class JobExecutor
{
    private string $privateDir;

    function __construct()
    {
        $this->privateDir = App::get()->getPrivateDir();
    }

    public function encodeForCLI(mixed $data) : string
    {
        $json = json_encode($data);
        return escapeshellarg($json);
    }

    /**
     * Execute a shell command in the background.
     *
     * @param string $command     The command to execute.
     * @param string $outputFile  The file where the command output should be directed. Defaults to /dev/null.
     * @return void
     */
    function runInBackground(string $command, string $outputFile = '/dev/null'): void
    {
        // Format the command to redirect its output and run it in the background
        $formattedCommand = sprintf('%s > %s 2>&1 & echo $!', $command, $outputFile);

        // Execute the command
        shell_exec($formattedCommand);
    }

    /**
     * TODO: this needs to take into account the fact that (while not likely to happen for MY use cases, it could)
     * command line arguments have a max size, often 2mb. In order to work around this, we could add functionality
     * to check if the size is reaching that limit, and if so, use temp files and xargs
     *
     * @param array $jobs
     * @return void
     */
    public function exec(array $jobs) : void
    {
        if(count($jobs) === 0)
            return;

        $command = "php " . $this->privateDir . "/runjob";

        foreach($jobs as $j)
            $command .= " " . $j[0] . " " . $this->encodeForCLI($j[1]);

        $this->runInBackground($command);
    }

    /* Function for parsing crontab entries and determining if they match the
     * execution time of this script
    *************************************************************************/
    function crontabMatchesNow(string $cron) : bool
    {
        $cronParts = explode(' ', $cron);

        if(count($cronParts) != 5)
            return false;

        list($min, $hour, $day, $mon, $week) = $cronParts;

        $to_check = ['min' => 'i', 'hour' => 'G', 'day' => 'j', 'mon' => 'n', 'week' => 'w'];
        $ranges = ['min' => '0-59', 'hour' => '0-23', 'day' => '1-31', 'mon' => '1-12', 'week' => '0-6'];

        foreach($to_check as $part => $c)
        {
            $val = ${$part};  // Using {} for clarity

            if ($val == '*')
                continue; // Wildcard matches everything, so continue to next loop iteration

            $values = [];

            /*For patters like:
                0-23/2
            */
            if(str_contains($val, '/'))
            {
                list($range, $steps) = explode('/', $val);
                $steps = (int) $steps;

                if($range == '*')
                    $range = $ranges[$part];

                list($start, $stop) = explode('-', $range);
                $start = (int) $start;
                $stop = (int) $stop;

                for($i = $start; $i <= $stop; $i += $steps)
                    $values[] = $i;
            }
            /*For patters like:
                2
                2,5,8
                2-23
            */
            else
            {
                $k = explode(',', $val);

                foreach($k as $v)
                {
                    if(str_contains($v, '-'))
                    {
                        list($start, $stop) = explode('-', $v);
                        $start = (int) $start;
                        $stop = (int) $stop;

                        for($i = $start; $i <= $stop; $i++)
                            $values[] = $i;
                    }
                    else
                        $values[] = (int) $v;
                }
            }

            if (!in_array((int) date($c), $values))
                return false;
        }

        return true;
    }
}