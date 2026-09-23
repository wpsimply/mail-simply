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
    ],

    'master' => [
        'user' => null,
        'password' => null,
    ],

    'login' => [
        'enabled' => true,
        'domain' => null,
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
    ],

    'limits' => [
        'page_size' => 50,
        // Bytes of attachments one message may carry, all together. Keep
        // PHP's upload_max_filesize and post_max_size at least this high.
        'attachments' => 26214400,
        'recipients' => 100,
    ],
];
