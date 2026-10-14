<?php

declare(strict_types=1);

namespace MailSimply;

use Throwable;

/**
 * The application's own log: one line per event, with what it was about as
 * JSON beside it.
 *
 * It is written to a file of its own (storage/logs/mail-simply.log unless
 * MAIL_SIMPLY_LOG names another), because PHP's error_log() goes wherever
 * the web server decides -- under PHP-FPM, often nowhere anybody looks. In a
 * container, "stderr" sends it to the container's own log instead. Where the
 * file cannot be written, PHP's error_log() is still the fallback, so an
 * event is never lost to a permissions problem.
 *
 * Never log a password, a sign-on token or a message body.
 */
final class Log
{
    private static ?string $path = null;

    /**
     * Point the log at where the configuration says.
     */
    public static function configure(Config $config): void
    {
        $path = $config->string('log.path');

        self::$path = $path !== '' ? $path : null;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function warning(string $message, array $context = []): void
    {
        self::write('WARNING', $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    /**
     * Format one log line.
     *
     * @param  array<string, mixed>  $context
     */
    public static function line(string $level, string $message, array $context = []): string
    {
        $context = array_map(
            static fn (mixed $value): mixed => $value instanceof Throwable ? $value::class.': '.$value->getMessage().' at '.$value->getFile().':'.$value->getLine() : $value,
            $context,
        );

        return sprintf(
            '[%s] %s: %s%s',
            gmdate('Y-m-d\TH:i:s\Z'),
            $level,
            $message,
            $context === [] ? '' : ' '.json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private static function write(string $level, string $message, array $context): void
    {
        $line = self::line($level, $message, $context);

        if (self::$path === 'stderr') {
            @file_put_contents('php://stderr', $line."\n");

            return;
        }

        if (self::$path !== null && self::append(self::$path, $line)) {
            return;
        }

        error_log('mail-simply: '.$line);
    }

    private static function append(string $path, string $line): bool
    {
        $dir = dirname($path);

        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            return false;
        }

        return @file_put_contents($path, $line."\n", FILE_APPEND | LOCK_EX) !== false;
    }
}
