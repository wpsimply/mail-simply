<?php

declare(strict_types=1);

namespace MailSimply\Smtp;

use RuntimeException;

/**
 * The SMTP server answered with something other than what the command needs:
 * a refused recipient, a message over the size limit, a failed login.
 */
final class Rejected extends RuntimeException
{
    public function __construct(public readonly string $reply, public readonly ?string $recipient = null)
    {
        parent::__construct($recipient === null ? $reply : sprintf('%s: %s', $recipient, $reply));
    }
}
