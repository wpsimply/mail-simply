<?php

declare(strict_types=1);

use MailSimply\Http;
use MailSimply\Session;
use MailSimply\SignOn;

$config = require dirname(__DIR__).'/bootstrap.php';

mail_simply_headers();

$session = new Session($config);
$token = (string) ($_GET['token'] ?? '');

try {
    $mailbox = (new SignOn($config))->redeem($token);
} catch (Throwable $e) {
    error_log('mail-simply: '.$e->getMessage());
    $mailbox = null;
}

if ($mailbox === null) {
    // A reload of the sign-on URL after it was spent lands back in the
    // session it already opened rather than on an error.
    if ($session->grant() !== null) {
        header('Location: ./');
        exit;
    }

    $lang = Http::lang($config);
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo $lang->get('This sign-in link is invalid, has expired or has already been used. Open webmail again from your control panel.');
    exit;
}

// A token for another mailbox replaces the session already open: the
// customer has just asked the panel for this one.
$session->signIn($mailbox['address'], $mailbox['name'], null);

header('Location: ./');
