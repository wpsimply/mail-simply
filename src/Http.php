<?php

declare(strict_types=1);

namespace MailSimply;

/**
 * What the entry points in public/ share: the language a request is answered
 * in, and how an error reaches the browser.
 */
final class Http
{
    /**
     * The language for this request: the mailbox's own choice when one is
     * signed in, then the browser's, then the configured default.
     */
    public static function lang(Config $config, ?string $address = null): Lang
    {
        $preferred = $address === null ? null : Api::preferences($config, $address)->language();

        return new Lang(Lang::negotiate($preferred, $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null, $config->string('language')));
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $data
     */
    public static function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    /**
     * Answer a failure as JSON: a user error with its own message, in the
     * request's language; anything else logged and answered in general terms.
     */
    public static function jsonError(\Throwable $e, Lang $lang): never
    {
        if ($e instanceof UserError) {
            self::json(['error' => $lang->get($e->getMessage(), $e->replace)], $e->status);
        }

        error_log('mail-simply: '.$e);
        self::json(['error' => $lang->get('Something went wrong. The error has been logged.')], 500);
    }

    /**
     * Answer a failure as a plain page, for requests a browser opens directly
     * (an attachment, a message frame).
     */
    public static function textError(\Throwable $e, Lang $lang): never
    {
        if (! $e instanceof UserError) {
            error_log('mail-simply: '.$e);
            $e = new UserError('Something went wrong. The error has been logged.', 500);
        }

        http_response_code($e->status);
        header('Content-Type: text/plain; charset=utf-8');
        echo $lang->get($e->getMessage(), $e->replace);
        exit;
    }

    /**
     * A Content-Disposition header value that carries any filename: an ASCII
     * fallback for old clients, and the real name RFC 6266 style.
     */
    public static function disposition(string $type, string $name): string
    {
        $ascii = (string) preg_replace('/[^\x20-\x7e]|["\\\\]/', '_', $name);

        return sprintf("%s; filename=\"%s\"; filename*=UTF-8''%s", $type, $ascii, rawurlencode($name));
    }
}
