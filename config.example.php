<?php

/*
 * Copy this file to config.php and adjust it. Every key is optional: anything
 * left out falls back to the environment (.env), and then to the defaults in
 * src/Config.php.
 */

return [
    'title' => 'Mail Simply',

    // Linked from the sign-in page and the account menu.
    'panel_url' => null,
    'support_url' => null,

    // en or hu: used when neither the user nor the browser asks for one.
    'language' => 'en',

    'imap' => [
        'host' => '127.0.0.1',
        'port' => 143,
        // ssl (implicit TLS, usually 993), starttls or none.
        'encryption' => 'starttls',
        'verify' => true,
        // 'ca_file' => '/etc/ssl/mail/ca.pem',
        'timeout' => 30,
    ],

    'smtp' => [
        'host' => '127.0.0.1',
        'port' => 587,
        'encryption' => 'starttls',
        'verify' => true,
        'timeout' => 30,
        'auth' => true,
    ],

    /*
     * One-click sign-in from a control panel: where tokens are redeemed, the
     * secret they are redeemed with, and the Dovecot master user the mailbox
     * is then opened as. See the README.
     */
    'sso' => [
        'url' => null,
        'secret' => null,
        'timeout' => 5,
        // The panel page that mints a token bound to the browser:
        // sso.php?start sends the browser there with ?binding=<hash>.
        'issue_url' => null,
        // Once the panel binds every token, refuse any token that is not.
        'require_binding' => false,
    ],

    'master' => [
        'user' => null,
        'password' => null,
    ],

    'login' => [
        'enabled' => true,
        'domain' => null,
        // Failed sign-ins allowed in 15 minutes before the form refuses, per
        // address and per client address; 0 for no limit. Behind a proxy,
        // let the web server set the real client address, or use 0 for the
        // per-client limit.
        'max_attempts' => 5,
        'max_attempts_per_client' => 30,
    ],

    'session' => [
        'save_path' => __DIR__.'/storage/sessions',
        'name' => 'MailSimplySession',
        'secure' => true,
        'idle_timeout' => 3600,
        'lifetime' => 43200,
    ],

    'storage' => [
        'users' => __DIR__.'/storage/users',
        'uploads' => __DIR__.'/storage/uploads',
        'throttle' => __DIR__.'/storage/throttle',
    ],

    'limits' => [
        'page_size' => 50,
        // Bytes of attachments one message may carry, all together. Keep
        // PHP's upload_max_filesize and post_max_size at least this high.
        'attachments' => 26214400,
        'recipients' => 100,
    ],
];
