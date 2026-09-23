<?php

declare(strict_types=1);

namespace MailSimply\Imap;

/**
 * A command argument sent as a literal: a byte count, then the bytes.
 *
 * Anything a quoted string cannot carry -- CR, LF, NUL, 8-bit text -- goes
 * this way, and so does every message body appended to a folder.
 */
final readonly class Literal
{
    public function __construct(public string $value) {}
}
