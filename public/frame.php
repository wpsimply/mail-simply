<?php

declare(strict_types=1);

use MailSimply\Http;
use MailSimply\Mailbox;
use MailSimply\Session;
use MailSimply\UserError;

/*
 * A message body, as a document of its own for the reader's frame.
 *
 * The interface loads this in <iframe sandbox="allow-same-origin
 * allow-popups allow-popups-to-escape-sandbox">: no script runs in it, and
 * links open in a new tab. The policy below is the second wall behind the
 * sanitizer: no scripts, no forms, no frames, and images only from here or
 * inline -- or from anywhere, once the reader has allowed remote content
 * (remote=1: for this message, or for every message from a trusted sender).
 */

$config = require dirname(__DIR__).'/bootstrap.php';

$session = new Session($config);
$grant = $session->grant();
$lang = Http::lang($config, $grant['address'] ?? null);
$session->release();

$remote = ($_GET['remote'] ?? '') === '1';
$images = $remote ? "'self' data: https: http:" : "'self' data:";

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');
header('Cache-Control: private, no-store');
header("Content-Security-Policy: default-src 'none'; img-src {$images}; style-src 'unsafe-inline'; font-src data:; frame-ancestors 'self'; base-uri 'none'; form-action 'none'");

if ($grant === null) {
    Http::textError(new UserError('Your session has ended. Sign in again.', 401), $lang);
}

$folder = (string) ($_GET['folder'] ?? '');
$uid = (int) ($_GET['uid'] ?? 0);
$mailbox = null;

try {
    $mailbox = Mailbox::open($config, $grant);

    $partUrl = static fn (string $section): string => 'attachment.php?'.http_build_query(['folder' => $folder, 'uid' => $uid, 'part' => $section, 'inline' => 1]);
    $body = $mailbox->body($folder, $uid, $remote, $partUrl, ($_GET['plain'] ?? '') !== '1');
} catch (Throwable $e) {
    Http::textError(Mailbox::explain($e) ?? $e, $lang);
} finally {
    $mailbox?->close();
}

header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="<?= htmlspecialchars($lang->code, ENT_QUOTES) ?>">
<head>
<meta charset="utf-8">
<meta name="mail-simply-blocked" content="<?= (int) $body['blocked'] ?>">
<meta name="color-scheme" content="light">
<style>
    html { background: #fff; color: #1d2527; }
    body { margin: 0; padding: 16px 20px 24px; font: 14px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; overflow-wrap: anywhere; }
    img { max-width: 100%; height: auto; }
    table { max-width: 100%; }
    pre { white-space: pre-wrap; }
    blockquote { margin: 0 0 0 4px; padding-left: 12px; border-left: 3px solid #c9d4d6; color: #4b5b5e; }
    .plain { white-space: pre-wrap; }
    .plain blockquote { margin: 0; }
    hr.part { border: 0; border-top: 1px dashed #c9d4d6; margin: 20px 0; }
    a { color: #0d6b63; }
</style>
</head>
<body><?= $body['html'] === '' ? '<p style="color:#6b7b7e">'.htmlspecialchars($lang->get('This message has no text.'), ENT_QUOTES).'</p>' : $body['html'] ?></body>
</html>
