<?php

declare(strict_types=1);

namespace MailSimply;

final class MailSimply
{
    /**
     * The application version. A release is tagged v<VERSION>, and
     * build/release.sh refuses a tag that says otherwise.
     */
    public const string VERSION = '0.1.0';

    /**
     * Get the formatted version.
     */
    public static function version(): string
    {
        return 'v'.self::VERSION;
    }

    /**
     * Get the agent the application identifies itself with: on the mail it
     * sends, and on the requests it makes to the panel.
     */
    public static function userAgent(): string
    {
        return 'mail-simply/'.self::VERSION;
    }
}
