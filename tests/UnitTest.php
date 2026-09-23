<?php

declare(strict_types=1);

namespace MailSimply\Tests;

use MailSimply\Api;
use MailSimply\Config;
use MailSimply\Env;
use MailSimply\Lang;
use MailSimply\Preferences;
use MailSimply\Session;
use MailSimply\SignOn;
use MailSimply\Uploads;
use MailSimply\UserError;
use RuntimeException;

/**
 * Everything around the mail itself: configuration, language, sessions,
 * settings, uploads and sign-on. No mail server needed.
 */
final class UnitTest extends TestCase
{
    public function testEnvironmentOverridesDefaultsAndRealVariablesWin(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir.'/.env', "MAIL_SIMPLY_IMAP_HOST=mail.example.com\nMAIL_SIMPLY_IMAP_PORT=993\nMAIL_SIMPLY_IMAP_ENCRYPTION=ssl\nMAIL_SIMPLY_SESSION_SECURE=false\nMAIL_SIMPLY_TITLE=\"Web Mail\"\nMAIL_SIMPLY_SSO_URL=\n");

        $overrides = Env::overrides($dir.'/.env', ['MAIL_SIMPLY_IMAP_PORT' => '9993']);

        self::assertSame('mail.example.com', $overrides['imap']['host']);
        self::assertSame(9993, $overrides['imap']['port'], 'The real environment wins over .env.');
        self::assertSame(false, $overrides['session']['secure']);
        self::assertSame('Web Mail', $overrides['title']);
        self::assertTrue(! isset($overrides['sso']), 'An empty variable means the default.');

        $config = Config::fromArray($dir, $overrides);
        self::assertSame(587, $config->int('smtp.port'));
        self::assertSame($dir.'/storage/users', $config->string('storage.users'));
    }

    public function testHomeMustBeADirectory(): void
    {
        self::assertSame('/app', Env::home('/app', []));
        self::assertSame(realpath(sys_get_temp_dir()), Env::home('/app', ['MAIL_SIMPLY_HOME' => sys_get_temp_dir()]));
        self::assertThrows(RuntimeException::class, static fn () => Env::home('/app', ['MAIL_SIMPLY_HOME' => '/does/not/exist']));
    }

    public function testSignOnNeedsEveryPiece(): void
    {
        $complete = ['sso' => ['url' => 'https://panel.test/webhooks/webmail/sessions', 'secret' => 's'], 'master' => ['user' => 'm', 'password' => 'p']];

        self::assertTrue(Config::fromArray('/app', $complete)->singleSignOn());
        self::assertTrue(! Config::fromArray('/app', array_replace_recursive($complete, ['master' => ['password' => '']]))->singleSignOn());
        self::assertTrue(! Config::fromArray('/app', array_replace_recursive($complete, ['sso' => ['secret' => null]]))->singleSignOn());
    }

    public function testLanguageIsNegotiated(): void
    {
        self::assertSame('hu', Lang::negotiate('hu', 'en-US,en', 'en'));
        self::assertSame('hu', Lang::negotiate(null, 'de-DE,de;q=0.9,hu;q=0.8,en;q=0.5', 'en'));
        self::assertSame('en', Lang::negotiate(null, 'de-DE,fr;q=0.9', 'en'));
        self::assertSame('en', Lang::negotiate('xx', 'hu;q=0', 'en'));
        self::assertSame('hu', Lang::negotiate(null, null, 'hu'));

        $hu = new Lang('hu');
        self::assertSame('Egy levélnek legfeljebb 5 címzettje lehet.', $hu->get('A message can go to at most :count recipients.', ['count' => 5]));
        self::assertSame('Untranslated :x', $hu->get('Untranslated :x'), 'A missing string stays English.');
    }

    public function testEveryHungarianStringKeepsItsPlaceholders(): void
    {
        foreach (require dirname(__DIR__).'/lang/hu.php' as $english => $hungarian) {
            preg_match_all('/:[a-z]+/', $english, $expected);
            preg_match_all('/:[a-z]+/', $hungarian, $actual);
            sort($expected[0]);
            sort($actual[0]);
            self::assertSame($expected[0], $actual[0], $english);
        }
    }

    public function testASignOnSessionHoldsNoPasswordAndAPasswordSessionHidesIt(): void
    {
        $config = Config::fromArray($this->tempDir(), ['session' => ['save_path' => $this->tempDir(), 'secure' => false]]);
        $session = new Session($config);

        $session->signIn('alice@example.test', 'Alice', null);
        $grant = $session->grant();
        self::assertSame(['address' => 'alice@example.test', 'name' => 'Alice', 'master' => true, 'password' => null], $grant);

        $session->signIn('bob@example.test', '', 'bob-secret');
        self::assertSame('bob-secret', $session->grant()['password']);
        self::assertTrue(! str_contains(serialize($_SESSION), 'bob-secret'), 'The password is not in the session in the clear.');
        self::assertTrue($session->verifyCsrf($session->csrf()));
        self::assertTrue(! $session->verifyCsrf('nope'));
        self::assertSame(1, preg_match('/^[a-f0-9]{32}$/', $session->uploadKey()));

        // Without the key cookie the session is worthless.
        $_COOKIE = [];
        self::assertSame(null, $session->grant());
        $session->signOut();
    }

    public function testPreferencesPersistAndRememberAddresses(): void
    {
        $dir = $this->tempDir();
        $preferences = new Preferences($dir, 'Alice@Example.test');

        $preferences->update(['name' => "  Alice\r\nAnderson ", 'signature' => '<p>Alice</p>', 'html' => false, 'language' => 'xx']);
        $preferences->remember([['name' => 'Bob', 'email' => 'Bob@Example.test'], ['name' => '', 'email' => 'alice@example.test']]);
        $preferences->remember([['name' => '', 'email' => 'bob@example.test'], ['name' => 'Carol', 'email' => 'carol@example.test']]);
        $preferences->trust('news@Shop.example', true);

        $reloaded = new Preferences($dir, 'alice@example.test');
        self::assertSame('Alice  Anderson', $reloaded->name());
        self::assertSame(false, $reloaded->html());
        self::assertSame(null, $reloaded->language(), 'An unknown language is not kept.');
        self::assertSame([['name' => 'Bob', 'email' => 'bob@example.test'], ['name' => 'Carol', 'email' => 'carol@example.test']], $reloaded->contacts(''), 'Most used first; never the mailbox itself.');
        self::assertSame([['name' => 'Carol', 'email' => 'carol@example.test']], $reloaded->contacts('car'));
        self::assertTrue($reloaded->trusts('offers@shop.example'), 'A trusted domain covers every sender in it.');
        self::assertTrue(! $reloaded->trusts('news@other.example'));
        self::assertSame(1, count(glob($dir.'/*.json') ?: []));
        self::assertTrue(! str_contains((string) implode('', glob($dir.'/*') ?: []), 'alice'), 'The file name gives no address away.');
    }

    public function testUploadsBelongToTheirSession(): void
    {
        $root = $this->tempDir();
        $mine = new Uploads($root, str_repeat('a', 32));
        $theirs = new Uploads($root, str_repeat('b', 32));
        $file = tempnam(sys_get_temp_dir(), 'ms');
        file_put_contents($file, 'data');

        $stored = $mine->store($file, '../../etc/pass"wd', 'text/plain');

        self::assertSame('passwd', $stored['name'], 'No path, no quote.');
        self::assertSame(4, $mine->total());
        self::assertTrue($mine->find($stored['id']) !== null);
        self::assertSame(null, $theirs->find($stored['id']), 'Another session cannot reach it.');
        self::assertSame(null, $mine->find('../'.$stored['id']));

        $mine->delete($stored['id']);
        self::assertSame(null, $mine->find($stored['id']));
        self::assertThrows(UserError::class, static fn () => (new Uploads($root, ''))->total());
    }

    public function testTheApiRefusesChangesWithoutTheCsrfToken(): void
    {
        $config = Config::fromArray($this->tempDir(), ['session' => ['save_path' => $this->tempDir(), 'secure' => false]]);
        $session = new Session($config);
        $session->signIn('alice@example.test', 'Alice', null);

        $api = new Api($config, $session);
        $error = self::assertThrows(UserError::class, static fn () => $api->handle('POST', 'delete', [], ['folder' => 'INBOX', 'uids' => [1]], 'wrong'));
        self::assertSame(419, $error->status);

        $error = self::assertThrows(UserError::class, static fn () => $api->handle('POST', 'messages', [], [], $session->csrf()));
        self::assertSame(405, $error->status, 'Reads are GET only.');

        $error = self::assertThrows(UserError::class, static fn () => $api->handle('GET', 'nonsense', [], []));
        self::assertSame(404, $error->status);

        $session->signOut();
        $error = self::assertThrows(UserError::class, static fn () => (new Api($config, new Session($config)))->handle('GET', 'folders', [], []));
        self::assertSame(401, $error->status);
    }

    public function testSignOnRedeemsAgainstThePanel(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir.'/router.php', <<<'PHP'
            <?php
            $ok = ($_SERVER['REQUEST_METHOD'] === 'POST') && (($_SERVER['HTTP_AUTHORIZATION'] ?? '') === 'Bearer panel-secret');
            if ($ok && $_SERVER['REQUEST_URI'] === '/sessions/'.str_repeat('a', 64)) {
                header('Content-Type: application/json');
                echo json_encode(['address' => 'alice@example.test', 'name' => 'Alice Anderson']);
                return;
            }
            http_response_code(404);
            PHP);

        $port = random_int(40000, 49000);
        // Failed redemptions are logged; not into the test output.
        $log = ini_set('error_log', '/dev/null');
        $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:'.$port, $dir.'/router.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);

        try {
            for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
                usleep(100000);
            }

            $config = static fn (string $secret): Config => Config::fromArray('/app', [
                'sso' => ['url' => 'http://127.0.0.1:'.$port.'/sessions/', 'secret' => $secret],
                'master' => ['user' => 'm', 'password' => 'p'],
            ]);

            self::assertSame(['address' => 'alice@example.test', 'name' => 'Alice Anderson'], (new SignOn($config('panel-secret')))->redeem(str_repeat('a', 64)));
            self::assertSame(null, (new SignOn($config('panel-secret')))->redeem(str_repeat('b', 64)), 'An unknown token.');
            self::assertSame(null, (new SignOn($config('wrong')))->redeem(str_repeat('a', 64)), 'The wrong secret.');
            self::assertSame(null, (new SignOn($config('panel-secret')))->redeem('../../etc'), 'Not a token at all.');
        } finally {
            proc_terminate($server);
            proc_close($server);
            ini_set('error_log', (string) $log);
        }
    }
}
