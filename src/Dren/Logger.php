<?php
declare(strict_types=1);

namespace Dren;

/**
 * Super basic logging class which writes log entries to a buffer, and then appends the contents of that buffer to
 * a log file at the end of script execution.
 */
class Logger
{
    /** @var array<string> */
    private static array $buffer = [];
    private static string $logFilePath;
    private function __construct() {}

    public static function init(string $logFilePath): void
    {
        self::$logFilePath = $logFilePath;
        register_shutdown_function([__CLASS__, 'flush']);
    }

    public static function write(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        self::$buffer[] = "--[$timestamp] |--MESSAGE--| $message";
    }

    public static function error(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        self::$buffer[] = "--[$timestamp] |--ERROR--| $message";
    }

    public static function debug(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        self::$buffer[] = "--[$timestamp] |--DEBUG--| $message";
    }

    public static function warning(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        self::$buffer[] = "--[$timestamp] |--WARNING--| $message";
    }

    public static function flush(): void
    {
        if (empty(self::$buffer)) return;

        $logData = implode("\n", self::$buffer);
        file_put_contents(self::$logFilePath, $logData . "\n", FILE_APPEND);
        self::$buffer = [];
    }
}