<?php

declare(strict_types=1);

use MailSimply\Compose;
use MailSimply\Http;
use MailSimply\Session;
use MailSimply\Uploads;
use MailSimply\UserError;

/*
 * An attachment for a message being written: one file per request, as
 * multipart/form-data under "file", with the CSRF token in X-CSRF-Token.
 */

$config = require dirname(__DIR__).'/bootstrap.php';

mail_simply_headers();

$session = new Session($config);
$grant = $session->grant();
$lang = Http::lang($config, $grant['address'] ?? null);

try {
    if ($grant === null) {
        throw new UserError('Your session has ended. Sign in again.', 401);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        throw new UserError('Method not allowed.', 405);
    }

    if (! $session->verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
        throw new UserError('Your session token is out of date. Reload the page.', 419);
    }

    $uploads = new Uploads($config->string('storage.uploads'), $session->uploadKey());
    $session->release();

    $file = $_FILES['file'] ?? null;
    $limit = $config->int('limits.attachments');

    // PHP drops a body larger than post_max_size without a word: no files,
    // no fields. That is the one case where both are empty.
    if (! is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_INI_SIZE || ($file['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE) {
        throw new UserError('The attachments are larger than the :size allowed.', 413, ['size' => Compose::bytes($limit)]);
    }

    if ($file['error'] !== UPLOAD_ERR_OK || ! is_uploaded_file((string) $file['tmp_name'])) {
        throw new UserError('The file could not be uploaded. Try again.');
    }

    if ((int) $file['size'] + $uploads->total() > $limit) {
        throw new UserError('The attachments are larger than the :size allowed.', 413, ['size' => Compose::bytes($limit)]);
    }

    $type = 'application/octet-stream';

    if (function_exists('finfo_open')) {
        $detected = (new finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
        $type = is_string($detected) && $detected !== '' ? $detected : $type;
    } elseif (is_string($file['type'] ?? null) && $file['type'] !== '') {
        $type = $file['type'];
    }

    $uploads->prune();
    $stored = $uploads->store((string) $file['tmp_name'], (string) $file['name'], $type);

    Http::json(['data' => ['id' => 'u:'.$stored['id'], 'name' => $stored['name'], 'type' => $stored['type'], 'size' => $stored['size']]]);
} catch (Throwable $e) {
    Http::jsonError($e, $lang);
}
