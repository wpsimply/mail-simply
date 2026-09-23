<?php

declare(strict_types=1);

use MailSimply\Http;
use MailSimply\Mailbox;
use MailSimply\Session;
use MailSimply\UserError;

/*
 * One part of a message, decoded, or with part=raw the whole message as it
 * sits on the server (an .eml file).
 *
 * Only images are ever shown in place, and only the types a browser renders
 * as images; everything else is a download. Every response also carries a
 * sandboxing policy, so a file opened directly in a tab -- an HTML
 * attachment, say -- runs nothing and reaches nothing from this origin.
 */

$config = require dirname(__DIR__).'/bootstrap.php';

$session = new Session($config);
$grant = $session->grant();
$lang = Http::lang($config, $grant['address'] ?? null);
$session->release();

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: sandbox; default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'");

if ($grant === null) {
    Http::textError(new UserError('Your session has ended. Sign in again.', 401), $lang);
}

$folder = (string) ($_GET['folder'] ?? '');
$uid = (int) ($_GET['uid'] ?? 0);
$section = (string) ($_GET['part'] ?? '');
$mailbox = null;

if ($section !== 'raw' && preg_match('/^\d+(\.\d+)*$/', $section) !== 1) {
    Http::textError(new UserError('This attachment no longer exists.', 404), $lang);
}

try {
    $mailbox = Mailbox::open($config, $grant);

    if ($section === 'raw') {
        $message = $mailbox->message($folder, $uid, false);
        $name = trim((string) preg_replace('/[\x00-\x1f\x7f\/\\\\:*?"<>|]+/u', ' ', $message['subject'])) ?: 'message';
        $name = mb_substr($name, 0, 100).'.eml';
        $type = 'message/rfc822';
        $encoding = '7bit';
        $section = '';
        $inline = false;
    } else {
        $info = $mailbox->part($folder, $uid, $section);
        $part = $info['part'];
        $name = $info['name'];
        $type = $part->isAttachedMessage() ? 'message/rfc822' : $part->mimeType();
        $encoding = $part->encoding;
        $inline = ($_GET['inline'] ?? '') === '1' && in_array($type, ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/bmp'], true);
    }

    // Headers go out before the first byte of the body does, which is the
    // moment the server is known to have the part.
    $started = false;
    $mailbox->streamPart($uid, $section, $encoding, static function (string $chunk) use (&$started, $type, $inline, $name): void {
        if (! $started) {
            $started = true;
            header('Content-Type: '.($inline ? $type : (preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#i', $type) === 1 ? $type : 'application/octet-stream')));
            header('Content-Disposition: '.Http::disposition($inline ? 'inline' : 'attachment', $name));
            header('Cache-Control: private, max-age=3600');
        }

        echo $chunk;
    });

    if (! $started) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: '.Http::disposition('attachment', $name));
    }
} catch (Throwable $e) {
    if (headers_sent()) {
        error_log('mail-simply: '.$e);
        exit;
    }

    Http::textError(Mailbox::explain($e) ?? $e, $lang);
} finally {
    $mailbox?->close();
}
