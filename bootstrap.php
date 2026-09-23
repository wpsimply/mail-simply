<?php

declare(strict_types=1);

use MailSimply\Config;
use MailSimply\Env;

if (PHP_VERSION_ID < 80400) {
    http_response_code(500);
    exit('Mail Simply requires PHP 8.4 or newer.');
}

foreach (['dom', 'iconv', 'mbstring', 'openssl', 'session', 'sodium'] as $extension) {
    if (! extension_loaded($extension)) {
        http_response_code(500);
        exit("Mail Simply requires the {$extension} extension.");
    }
}

// Installed with composer create-project (or composer install in a clone),
// vendor/autoload.php exists and is used, so anything config.php pulls in
// through Composer loads too. A release zip has no vendor directory, and
// neither does the package when another project requires it: the app has no
// dependencies, so its own classes are all there is to load.
if (is_file(__DIR__.'/vendor/autoload.php')) {
    require __DIR__.'/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class): void {
        if (str_starts_with($class, 'MailSimply\\')) {
            $file = __DIR__.'/src/'.str_replace('\\', '/', substr($class, strlen('MailSimply\\'))).'.php';

            if (is_file($file)) {
                require $file;
            }
        }
    });
}

/**
 * Headers every response carries: nothing may frame the app, the sign-on
 * token in the URL must never leak through a referrer, and scripts only load
 * from this origin. Alpine evaluates its directives with Function(), which is
 * what 'unsafe-eval' is for; no directive is ever built from message data.
 *
 * Message bodies are not rendered under this policy: they load in a
 * sandboxed frame from frame.php, which sends a stricter one of its own.
 */
function mail_simply_headers(): void
{
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; connect-src 'self'; frame-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
}

// .env, config.php and storage/ live here, or in MAIL_SIMPLY_HOME when the
// app is installed as a Composer dependency and must survive updates.
try {
    $home = Env::home(__DIR__);
} catch (RuntimeException $e) {
    http_response_code(500);
    exit($e->getMessage());
}

return Config::load($home);
