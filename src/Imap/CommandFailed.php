<?php

declare(strict_types=1);

namespace MailSimply\Imap;

use RuntimeException;

/**
 * The server answered a command with NO or BAD.
 *
 * The text is the server's own, which Dovecot keeps short and readable
 * ("Mailbox doesn't exist: Foo"), so it is shown to the user where the failure
 * is theirs to fix. The response code (`[ALREADYEXISTS]`, `[OVERQUOTA]`, ...)
 * is kept separately for code that wants to tell cases apart.
 */
final class CommandFailed extends RuntimeException
{
    public function __construct(
        public readonly string $status,
        public readonly ?string $responseCode,
        public readonly string $text,
        public readonly string $command,
    ) {
        parent::__construct(sprintf('%s failed: %s %s', $command, $status, $text));
    }
}
