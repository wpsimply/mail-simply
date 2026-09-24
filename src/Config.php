<?php

declare(strict_types=1);

namespace MailSimply;

/**
 * The application's configuration: the defaults below, overlaid with whatever
 * the environment and config.php set.
 */
final class Config
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function __construct(private readonly array $values) {}

    /**
     * Load the configuration for the application in the given directory.
     *
     * Three layers, each overriding the one before: the defaults below, the
     * MAIL_SIMPLY_* environment (.env, with the real environment winning), and
     * config.php. Use whichever suits the deployment; most need only one.
     */
    public static function load(string $root): self
    {
        $values = self::merge(self::defaults($root), Env::overrides($root.'/.env'));

        $file = $root.'/config.php';
        $overrides = is_file($file) ? require $file : [];

        return new self(self::merge($values, is_array($overrides) ? $overrides : []));
    }

    /**
     * Build a config from an array, over the defaults. Used by the tests.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function fromArray(string $root, array $overrides): self
    {
        return new self(self::merge(self::defaults($root), $overrides));
    }

    /**
     * Read a value by dot-separated path.
     */
    public function get(string $path, mixed $default = null): mixed
    {
        $value = $this->values;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    public function int(string $path): int
    {
        return (int) $this->get($path);
    }

    public function string(string $path): string
    {
        return trim((string) $this->get($path));
    }

    public function bool(string $path): bool
    {
        return (bool) $this->get($path);
    }

    /**
     * Whether the panel can sign mailboxes in: it needs somewhere to redeem
     * tokens, and a master user to open the mailbox with afterwards.
     */
    public function singleSignOn(): bool
    {
        return $this->string('sso.url') !== ''
            && $this->string('sso.secret') !== ''
            && $this->string('master.user') !== ''
            && $this->string('master.password') !== '';
    }

    /**
     * @return array<string, mixed>
     */
    private static function defaults(string $root): array
    {
        return [
            'title' => 'Mail Simply',
            'panel_url' => null,
            'support_url' => null,
            // The interface language when neither the user nor the browser
            // asks for one it has.
            'language' => 'en',
            'imap' => [
                'host' => '127.0.0.1',
                'port' => 143,
                // ssl (implicit TLS, usually 993), starttls or none.
                'encryption' => 'starttls',
                'verify' => true,
                'ca_file' => null,
                // Dovecot answers a failed login only after a delay, which
                // grows with repeated failures, so this is not kept short.
                'timeout' => 30,
            ],
            'smtp' => [
                'host' => '127.0.0.1',
                'port' => 587,
                'encryption' => 'starttls',
                'verify' => true,
                'ca_file' => null,
                'timeout' => 30,
                // Authenticate as the signed-in mailbox. Off only for a relay
                // that trusts this host outright.
                'auth' => true,
            ],
            // The Dovecot master user sign-on sessions authenticate as, on the
            // mailbox's behalf. Never used for the login form.
            'master' => [
                'user' => null,
                'password' => null,
            ],
            'sso' => [
                // Where a sign-on token is redeemed: POST {url}/{token}.
                'url' => null,
                'secret' => null,
                'timeout' => 5,
                // Where sso.php?start sends the browser for a bound token.
                'issue_url' => null,
                // Refuse tokens that are not bound to a browser.
                'require_binding' => false,
            ],
            'login' => [
                // The password form. Sign-on keeps working without it.
                'enabled' => true,
                // Appended to a bare user name typed into the form.
                'domain' => null,
                // Failed sign-ins allowed in 15 minutes, per address and per
                // client address, before the form refuses; 0 for no limit.
                'max_attempts' => 5,
                'max_attempts_per_client' => 30,
            ],
            'session' => [
                'save_path' => $root.'/storage/sessions',
                'name' => 'MailSimplySession',
                'secure' => true,
                'idle_timeout' => 3600,
                'lifetime' => 43200,
            ],
            'storage' => [
                // Each mailbox's settings and collected addresses.
                'users' => $root.'/storage/users',
                // Attachments uploaded for a message not yet sent.
                'uploads' => $root.'/storage/uploads',
                // Failed sign-in counts, for the login form's limits.
                'throttle' => $root.'/storage/throttle',
            ],
            'limits' => [
                'page_size' => 50,
                // Bytes of attachments one message may carry, all together.
                'attachments' => 26214400,
                'recipients' => 100,
            ],
        ];
    }

    /**
     * Recursively overlay associative arrays; lists and scalars are replaced.
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function merge(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            $base[$key] = is_array($value) && isset($base[$key]) && is_array($base[$key]) && ! array_is_list($value)
                ? self::merge($base[$key], $value)
                : $value;
        }

        return $base;
    }
}
