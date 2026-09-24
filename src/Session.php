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
    /**
     * How long a browser may take to come back from the panel with a token
     * bound to its sign-on proof, signing in to the panel included.
     */
    private const int SIGN_ON_SECONDS = 600;

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

        session_name($this->cookieName());
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

        // The proof has done its job; it may not bind a second token.
        $this->setSignOnCookie('', time() - 3600);

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
     * Give this browser a fresh sign-on proof, and return the binding the
     * panel is to tie the token it mints to. See {@see SignOn}.
     */
    public function startSignOn(): string
    {
        $proof = bin2hex(random_bytes(32));
        $this->setSignOnCookie($proof, time() + self::SIGN_ON_SECONDS);
        $_COOKIE[$this->signOnCookieName()] = $proof;

        return SignOn::binding($proof);
    }

    /**
     * The sign-on proof this browser holds, if any.
     */
    public function signOnProof(): ?string
    {
        $proof = $_COOKIE[$this->signOnCookieName()] ?? null;

        return is_string($proof) && preg_match('/^[a-f0-9]{64}$/', $proof) === 1 ? $proof : null;
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
        return $this->cookieName().'Key';
    }

    private function signOnCookieName(): string
    {
        return $this->cookieName().'SignOn';
    }

    private function setSignOnCookie(string $value, int $expires): void
    {
        if (! headers_sent()) {
            setcookie($this->signOnCookieName(), $value, [...$this->cookieOptions(), 'expires' => $expires]);
        }
    }

    /**
     * The session cookie's name. Over HTTPS it carries the __Host- prefix:
     * the browser then only accepts the cookie from this exact host, so a
     * site on a sibling subdomain -- a customer's, on a hosting server --
     * cannot plant a session of its own in the user's browser.
     */
    private function cookieName(): string
    {
        $name = (string) $this->config->get('session.name');

        return (bool) $this->config->get('session.secure') && ! str_starts_with($name, '__Host-') ? '__Host-'.$name : $name;
    }

    private function setKeyCookie(string $value, int $expires): void
    {
        if (! headers_sent()) {
            setcookie($this->keyCookieName(), $value, [...$this->cookieOptions(), 'expires' => $expires]);
        }
    }

    /**
     * Both cookies end with the browser session, and are never shared with
     * other subdomains, whatever php.ini's session.cookie_domain says.
     *
     * @return array{path: string, domain: string, secure: bool, httponly: bool, samesite: string}
     */
    private function cookieOptions(): array
    {
        return [
            'path' => '/',
            'domain' => '',
            'secure' => (bool) $this->config->get('session.secure'),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }
}
