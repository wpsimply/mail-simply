<?php

declare(strict_types=1);

namespace MailSimply\Imap;

use RuntimeException;

/**
 * The server refused the credentials: a wrong password, a mailbox that may
 * not sign in, or a master user it does not accept.
 */
final class AuthenticationFailed extends RuntimeException {}
