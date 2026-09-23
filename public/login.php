<?php

declare(strict_types=1);

use MailSimply\Imap\AuthenticationFailed;
use MailSimply\Imap\Client;
use MailSimply\Mailbox;
use MailSimply\Session;

$config = require dirname(__DIR__).'/bootstrap.php';

mail_simply_headers();

$session = new Session($config);
$session->start();

$fail = static function (string $error, string $address): never {
    $_SESSION['login'] = ['error' => $error, 'address' => mb_substr($address, 0, 254)];
    header('Location: ./');
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || ! $config->bool('login.enabled')) {
    header('Location: ./');
    exit;
}

$address = trim((string) ($_POST['address'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
$expected = (string) ($_SESSION['login_csrf'] ?? '');

if ($expected === '' || ! hash_equals($expected, (string) ($_POST['csrf'] ?? ''))) {
    $fail('The form expired. Try again.', $address);
}

// A bare user name is completed with the configured domain.
$domain = $config->string('login.domain');

if ($address !== '' && ! str_contains($address, '@') && $domain !== '') {
    $address .= '@'.$domain;
}

if ($address === '' || $password === '' || strlen($address) > 254 || strlen($password) > 1024) {
    $fail('Enter your email address and password.', $address);
}

try {
    $imap = Client::connect(Mailbox::serverOptions($config, 'imap'));
    $imap->authenticate($address, $password);
    $imap->logout();
} catch (AuthenticationFailed) {
    $fail('The email address or password is incorrect.', $address);
} catch (Throwable $e) {
    error_log('mail-simply: '.$e->getMessage());
    $fail('The mail server cannot be reached right now. Try again in a moment.', $address);
}

$session->signIn($address, '', $password);

header('Location: ./');
