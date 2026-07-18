<?php

declare(strict_types=1);

namespace NexWaypoint\Core;

/**
 * Structured JSON-lines file logger (PHP has no structlog; this is the
 * hand-rolled equivalent). One JSON object per line, safe for `tail -f`
 * and for feeding into any log aggregator later.
 */
final class Logger
{
    private const LEVELS = ['debug', 'info', 'warning', 'error', 'critical'];

    private string $logFile;
    private string $minLevel;

    public function __construct(string $logFile, string $minLevel = 'info')
    {
        $dir = dirname($logFile);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Unable to create log directory: {$dir}");
        }
        $this->logFile = $logFile;
        $this->minLevel = in_array($minLevel, self::LEVELS, true) ? $minLevel : 'info';
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    private function log(string $level, string $message, array $context): void
    {
        if (array_search($level, self::LEVELS, true) < array_search($this->minLevel, self::LEVELS, true)) {
            return;
        }

        $entry = [
            'timestamp' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];

        $line = json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL;
        if ($line === false) {
            // Fall back to a safe, non-throwing representation rather than
            // losing the log line because context wasn't JSON-encodable.
            $line = json_encode([
                'timestamp' => $entry['timestamp'],
                'level' => $level,
                'message' => $message,
                'context_error' => 'context not JSON-encodable',
            ]) . PHP_EOL;
        }

        // Errors writing to the log itself must never bring down the
        // request/cron job -- suppress and fall back to error_log().
        $written = @file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
        if ($written === false) {
            error_log('[NexWAYPOINT] failed to write log file, falling back: ' . $line);
        }
    }
}
