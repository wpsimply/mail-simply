<?php

declare(strict_types=1);

use MailSimply\Api;
use MailSimply\Http;
use MailSimply\Session;

$config = require dirname(__DIR__).'/bootstrap.php';

mail_simply_headers();

$session = new Session($config);
$lang = Http::lang($config, $session->grant()['address'] ?? null);
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$body = [];

if ($method === 'POST') {
    $body = json_decode((string) file_get_contents('php://input'), true);

    if (! is_array($body)) {
        Http::json(['error' => 'Expected a JSON body.'], 400);
    }
}

try {
    $data = (new Api($config, $session))->handle($method, (string) ($_GET['action'] ?? ''), $_GET, $body, $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
} catch (Throwable $e) {
    Http::jsonError($e, $lang);
}

Http::json(['data' => $data]);
