<?php

namespace Dren;

class JobExecutor
{
    public function prepareJsonForCLI(mixed $data) : string
    {
        $json = json_encode($data);
        return escapeshellarg($json);
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