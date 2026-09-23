<?php

declare(strict_types=1);

namespace MailSimply\Tests;

use Closure;
use MailSimply\Config;
use MailSimply\Mailbox;
use RuntimeException;
use Throwable;

final class Skipped extends RuntimeException {}

final class AssertionFailed extends RuntimeException {}

abstract class TestCase
{
    /**
     * The mailboxes tests/dovecot provisions, and the master user that may
     * sign in on their behalf.
     */
    protected const string ALICE = 'alice@example.test';

    protected const string ALICE_PASSWORD = 'alice-test-password';

    protected const string BOB = 'bob@example.test';

    protected const string MASTER = 'webmail@example.test';

    protected const string MASTER_PASSWORD = 'master-test-password';

    /** @var list<Mailbox> */
    private array $opened = [];

    /** @var list<string> */
    private array $temporary = [];

    public function setUp(): void {}

    public function tearDown(): void
    {
        foreach ($this->opened as $mailbox) {
            $mailbox->close();
        }

        foreach ($this->temporary as $dir) {
            self::remove($dir);
        }
    }

    protected static function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            throw new AssertionFailed(trim($message.' Expected '.var_export($expected, true).', got '.var_export($actual, true).'.'));
        }
    }

    protected static function assertTrue(bool $condition, string $message = 'Expected true.'): void
    {
        if (! $condition) {
            throw new AssertionFailed($message);
        }
    }

    protected static function assertContains(string $needle, string $haystack, string $message = ''): void
    {
        if (! str_contains($haystack, $needle)) {
            throw new AssertionFailed(trim($message.' Expected to find '.var_export($needle, true).' in '.var_export(mb_substr($haystack, 0, 600), true).'.'));
        }
    }

    protected static function assertNotContains(string $needle, string $haystack, string $message = ''): void
    {
        if (str_contains($haystack, $needle)) {
            throw new AssertionFailed(trim($message.' Did not expect '.var_export($needle, true).' in '.var_export(mb_substr($haystack, 0, 600), true).'.'));
        }
    }

    /**
     * @param  class-string<Throwable>  $class
     */
    protected static function assertThrows(string $class, Closure $callback, ?string $messageContains = null): Throwable
    {
        try {
            $callback();
        } catch (Throwable $e) {
            if (! $e instanceof $class) {
                throw new AssertionFailed(sprintf('Expected %s, got %s: %s', $class, $e::class, $e->getMessage()));
            }

            if ($messageContains !== null && ! str_contains($e->getMessage(), $messageContains)) {
                throw new AssertionFailed(sprintf('Expected the message to contain "%s", got "%s".', $messageContains, $e->getMessage()));
            }

            return $e;
        }

        throw new AssertionFailed(sprintf('Expected %s to be thrown.', $class));
    }

    /**
     * A config pointing at the test mail server, or a skip when none is
     * configured.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function config(array $overrides = []): Config
    {
        $imap = getenv('MAIL_SIMPLY_TEST_IMAP') ?: null;
        $smtp = getenv('MAIL_SIMPLY_TEST_SMTP') ?: null;

        if ($imap === null || $smtp === null) {
            throw new Skipped('No test mail server configured.');
        }

        [$imapHost, $imapPort] = explode(':', $imap) + [1 => '143'];
        [$smtpHost, $smtpPort] = explode(':', $smtp) + [1 => '587'];
        $storage = $this->tempDir();

        return Config::fromArray($storage, array_replace_recursive([
            'imap' => ['host' => $imapHost, 'port' => (int) $imapPort, 'encryption' => 'none', 'timeout' => 30],
            'smtp' => ['host' => $smtpHost, 'port' => (int) $smtpPort, 'encryption' => 'none'],
            'master' => ['user' => self::MASTER, 'password' => self::MASTER_PASSWORD],
            'storage' => ['users' => $storage.'/users', 'uploads' => $storage.'/uploads'],
            'session' => ['save_path' => $storage.'/sessions', 'secure' => false],
        ], $overrides));
    }

    /**
     * The grant a sign-on session holds for the address.
     *
     * @return array{address: string, name: string, master: bool, password: ?string}
     */
    protected static function grant(string $address = self::ALICE, string $name = 'Alice Test'): array
    {
        return ['address' => $address, 'name' => $name, 'master' => true, 'password' => null];
    }

    /**
     * A mailbox signed in through the master user, with every folder emptied
     * and every folder but the special ones deleted.
     */
    protected function mailbox(string $address = self::ALICE, ?Config $config = null): Mailbox
    {
        $mailbox = Mailbox::open($config ?? $this->config(), self::grant($address));
        $this->opened[] = $mailbox;

        foreach (array_reverse($mailbox->folders()) as $folder) {
            if ($folder['role'] === null && $folder['id'] !== 'INBOX') {
                $mailbox->imap()->delete($folder['id']);

                continue;
            }

            if ($folder['selectable']) {
                $mailbox->imap()->select($folder['id'], true);
                $mailbox->imap()->expunge($mailbox->imap()->search(['ALL']));
            }
        }

        return new Mailbox($mailbox->imap(), $address);
    }

    /**
     * Messages Mailpit has caught, newest first, or a skip without Mailpit.
     *
     * @return list<array<string, mixed>>
     */
    protected static function caught(): array
    {
        $url = getenv('MAIL_SIMPLY_TEST_MAILPIT') ?: null;

        if ($url === null) {
            throw new Skipped('No Mailpit configured.');
        }

        $body = @file_get_contents(rtrim($url, '/').'/api/v1/messages');

        return is_string($body) ? (json_decode($body, true)['messages'] ?? []) : [];
    }

    /**
     * The raw source of a message Mailpit caught.
     */
    protected static function caughtSource(string $id): string
    {
        return (string) @file_get_contents(rtrim((string) getenv('MAIL_SIMPLY_TEST_MAILPIT'), '/').'/api/v1/message/'.$id.'/raw');
    }

    protected function tempDir(): string
    {
        $dir = sys_get_temp_dir().'/mail-simply-test-'.bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        $this->temporary[] = $dir;

        return $dir;
    }

    /**
     * A message as it arrives from elsewhere, CRLF line endings and all.
     */
    protected static function raw(string $message): string
    {
        return str_replace("\n", "\r\n", str_replace("\r\n", "\n", $message));
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && ! is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove($path.'/'.$entry);
                }
            }

            @rmdir($path);
        } else {
            @unlink($path);
        }
    }
}
