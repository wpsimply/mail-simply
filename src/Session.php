<?php

declare(strict_types=1);

namespace MailSimply;

use SodiumException;

/**
 * The signed-in session: whose mailbox it is, how it authenticates, and its
 * CSRF token.
 *
 * A session opened by sign-on holds no password at all: the master user
 * authenticates on the mailbox's behalf, and its credentials come from the
 * configuration, never from the session. A session opened with the login
 * form keeps the mailbox password, encrypted, under a key that lives only in
 * a cookie of its own: the session file alone, read off disk or out of a
 * backup, does not give the password away, and neither does the cookie alone.
 */
final class Session
{
    public function __construct(private readonly Config $config) {}

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) $this->config->int('session.lifetime'));

        // Debian and Ubuntu turn PHP's own collection off and clean only
        // their default directory from cron; nothing would ever clean ours.
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');

        $savePath = (string) $this->config->get('session.save_path');

        if ($savePath !== '') {
            session_save_path($savePath);
        }

        session_name((string) $this->config->get('session.name'));
        session_set_cookie_params(['lifetime' => 0, ...$this->cookieOptions()]);

        session_start();
    }

    /**
     * Replace whatever session this browser had with one for the mailbox.
     *
     * @param  ?string  $password  null for a sign-on session, which the master user authenticates
     */
    public function signIn(string $address, string $name, ?string $password): void
    {
        $this->start();
        $_SESSION = [];
        session_regenerate_id(true);

        $_SESSION['grant'] = ['address' => $address, 'name' => $name, 'master' => $password === null];
        $_SESSION['secret'] = null;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        $_SESSION['uploads'] = bin2hex(random_bytes(16));
        $_SESSION['created_at'] = time();
        $_SESSION['seen_at'] = time();

        if ($password !== null) {
            $key = sodium_crypto_secretbox_keygen();
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $_SESSION['secret'] = base64_encode($nonce.sodium_crypto_secretbox($password, $nonce, $key));

            $encodedKey = sodium_bin2base64($key, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
            $this->setKeyCookie($encodedKey, 0);
            // The rest of this request reads the password back with the new key.
            $_COOKIE[$this->keyCookieName()] = $encodedKey;

            sodium_memzero($key);
        }
    }

    /**
     * The mailbox this session is for, with its password decrypted, or null
     * when signed out or timed out.
     *
     * @return array{address: string, name: string, master: bool, password: ?string}|null
     */
    public function grant(): ?array
    {
        $this->start();

        if (! isset($_SESSION['grant'], $_SESSION['created_at'], $_SESSION['seen_at'])) {
            return null;
        }

        $now = time();

        if ($now - $_SESSION['seen_at'] > $this->config->int('session.idle_timeout')
            || $now - $_SESSION['created_at'] > $this->config->int('session.lifetime')) {
            $this->signOut();

            return null;
        }

        $grant = [...$_SESSION['grant'], 'password' => null];

        if (! $grant['master']) {
            $password = $this->decrypt((string) ($_SESSION['secret'] ?? ''));

            // The key cookie is gone or was changed: the session cannot be used.
            if ($password === null) {
                $this->signOut();

                return null;
            }

            $grant['password'] = $password;
        }

        $_SESSION['seen_at'] = $now;

        return $grant;
    }

    /**
     * The name of this session's upload area, which nothing else can guess.
     */
    public function uploadKey(): string
    {
        return (string) ($_SESSION['uploads'] ?? '');
    }

    public function csrf(): string
    {
        return (string) ($_SESSION['csrf'] ?? '');
    }

    public function verifyCsrf(?string $token): bool
    {
        $expected = $this->csrf();

        return $expected !== '' && is_string($token) && hash_equals($expected, $token);
    }

    /**
     * Release the session lock so parallel requests from one tab -- a list
     * loading while a message opens -- do not queue behind each other.
     */
    public function release(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    public function signOut(): void
    {
        $this->start();
        $_SESSION = [];
        session_destroy();

        if (! headers_sent()) {
            setcookie(session_name(), '', [...$this->cookieOptions(), 'expires' => time() - 3600]);
        }

        $this->setKeyCookie('', time() - 3600);
    }

    private function decrypt(string $secret): ?string
    {
        $encoded = $_COOKIE[$this->keyCookieName()] ?? null;
        $box = base64_decode($secret, true);

        if (! is_string($encoded) || $box === false || strlen($box) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        try {
            $key = sodium_base642bin($encoded, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        } catch (SodiumException) {
            return null;
        }

        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            return null;
        }

        $plain = sodium_crypto_secretbox_open(
            substr($box, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($box, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $key,
        );

        sodium_memzero($key);

        return $plain === false ? null : $plain;
    }

    private function keyCookieName(): string
    {
        return $this->config->get('session.name').'Key';
    }

    private function setKeyCookie(string $value, int $expires): void
    {
        if (! headers_sent()) {
            setcookie($this->keyCookieName(), $value, [...$this->cookieOptions(), 'expires' => $expires]);
        }
    }

    /**
     * Both cookies end with the browser session.
     *
     * @return array{path: string, secure: bool, httponly: bool, samesite: string}
     */
    private function cookieOptions(): array
    {
        return [
            'path' => '/',
            'secure' => (bool) $this->config->get('session.secure'),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }
}
