<?php

declare(strict_types=1);

namespace MailSimply\Tests;

use MailSimply\Config;
use MailSimply\LoginThrottle;
use MailSimply\Session;
use MailSimply\SignOn;

/**
 * Sign-on bound to a browser, the session cookies, and the login form's limits.
 */
final class SecurityTest extends TestCase
{
    public function testASignOnTokenOnlySignsInTheBrowserHoldingItsProof(): void
    {
        $proof = bin2hex(random_bytes(32));
        $bound = ['address' => 'info@example.com', 'name' => 'Example', 'binding' => SignOn::binding($proof)];
        $signOn = new SignOn(Config::fromArray('/app', []));

        self::assertSame(['address' => 'info@example.com', 'name' => 'Example'], $signOn->accept($bound, $proof));

        // Someone else's link, opened in a browser without the proof, or with another one.
        self::assertSame(null, $signOn->accept($bound, null));
        self::assertSame(null, $signOn->accept($bound, bin2hex(random_bytes(32))));
        self::assertSame(null, $signOn->accept([...$bound, 'binding' => ['x']], $proof));

        // Unbound tokens work until binding is required.
        $unbound = ['address' => 'info@example.com'];
        self::assertSame('info@example.com', $signOn->accept($unbound, null)['address'] ?? null);
        self::assertSame(null, (new SignOn(Config::fromArray('/app', ['sso' => ['require_binding' => true]])))->accept($unbound, $proof));
    }

    public function testSignOnStartsWithAProofOnlyThisBrowserHolds(): void
    {
        $dir = $this->tempDir();
        $session = new Session(Config::fromArray($dir, ['session' => ['save_path' => $dir, 'secure' => false]]));

        self::assertSame(null, $session->signOnProof());

        $binding = $session->startSignOn();
        $proof = $session->signOnProof();

        self::assertTrue($proof !== null && SignOn::binding($proof) === $binding);

        $_COOKIE['MailSimplySessionSignOn'] = 'not a proof';
        self::assertSame(null, $session->signOnProof());
        unset($_COOKIE['MailSimplySessionSignOn']);
    }

    public function testSecureSessionCookiesCannotBeSetFromASiblingSubdomain(): void
    {
        $dir = $this->tempDir();
        $session = new Session(Config::fromArray($dir, ['session' => ['save_path' => $dir, 'secure' => true]]));

        $session->signIn('info@example.com', 'Example', 'hunter2');

        self::assertSame('__Host-MailSimplySession', session_name());
        self::assertTrue(isset($_COOKIE['__Host-MailSimplySessionKey']));
        self::assertSame('', session_get_cookie_params()['domain']);
        self::assertSame('hunter2', $session->grant()['password'] ?? null);

        session_write_close();
        session_name('MailSimplySession');
    }

    public function testTheLoginFormRefusesAfterTooManyFailures(): void
    {
        $dir = $this->tempDir().'/throttle';
        $throttle = new LoginThrottle($dir, 3, 5);

        for ($i = 0; $i < 3; $i++) {
            self::assertTrue(! $throttle->blocked('Info@Example.com', '203.0.113.7'));
            $throttle->failed('info@example.com', '203.0.113.7');
        }

        self::assertTrue($throttle->blocked('INFO@example.com', '198.51.100.1'), 'The address is limited, whatever the case and client.');
        self::assertTrue(! $throttle->blocked('other@example.com', '198.51.100.1'));

        // One client guessing across many addresses meets the client limit.
        $throttle->failed('a@example.com', '203.0.113.7');
        $throttle->failed('b@example.com', '203.0.113.7');
        self::assertTrue($throttle->blocked('c@example.com', '203.0.113.7'));

        // Signing in clears the address's count.
        $throttle->succeeded('info@example.com');
        self::assertTrue(! $throttle->blocked('info@example.com', '198.51.100.1'));

        // The files say nothing about whom they count.
        self::assertTrue(! str_contains(implode(' ', glob($dir.'/*') ?: []), 'example'));

        // No limit at all when both are 0.
        $open = new LoginThrottle($dir, 0, 0);
        self::assertTrue(! $open->blocked('a@example.com', '203.0.113.7'));
    }
}
