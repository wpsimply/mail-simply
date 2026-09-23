<?php

declare(strict_types=1);

namespace MailSimply;

use RuntimeException;

/**
 * A failure whose message is safe, and meant, to be shown to the user.
 *
 * The message is an English source string; {@see Lang} translates it on the
 * way out, with the replacements filled in after translation.
 */
class UserError extends RuntimeException
{
    /**
     * @param  array<string, string|int>  $replace
     */
    public function __construct(string $message, public readonly int $status = 422, public readonly array $replace = [])
    {
        parent::__construct($message);
    }
}
