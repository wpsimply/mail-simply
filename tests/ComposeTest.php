<?php

declare(strict_types=1);

namespace MailSimply\Tests;

use MailSimply\Compose;
use MailSimply\Config;
use MailSimply\Lang;
use MailSimply\Mailbox;
use MailSimply\Mime\Header;
use MailSimply\Preferences;
use MailSimply\Uploads;
use MailSimply\UserError;

/**
 * Writing, sending and saving mail against a real Dovecot, with Mailpit
 * catching what submission relays: see tests/dovecot.
 */
final class ComposeTest extends TestCase
{
    public function testSendingSubmitsAsTheMailboxAndKeepsACopy(): void
    {
        $config = $this->config();
        $mailbox = $this->mailbox(config: $config);
        $compose = $this->compose($config, $mailbox);
        $subject = 'Send test '.bin2hex(random_bytes(4));

        $result = $compose->send([
            'to' => [['name' => 'Bob', 'email' => self::BOB]],
            'bcc' => [['name' => '', 'email' => 'hidden@example.test']],
            'subject' => $subject,
            'html' => '<p>Hi <b>Bob</b></p><script>alert(1)</script><img src="https://example.test/x.png">',
        ]);

        self::assertSame(['sent' => true, 'savedToSent' => true], $result);

        $sent = $mailbox->messages('Sent', 1, 50)['messages'];
        self::assertSame([$subject], array_column($sent, 'subject'));
        self::assertSame(true, $sent[0]['flags']['seen']);

        $copy = $mailbox->message('Sent', $sent[0]['uid']);
        self::assertSame([['name' => '', 'email' => 'hidden@example.test']], $copy['bcc'], 'The sent copy remembers the Bcc.');
        self::assertSame([['name' => 'Alice Test', 'email' => self::ALICE]], $copy['from']);

        $caught = self::waitForCaught($subject);
        $source = self::caughtSource($caught['ID']);
        // Mailpit prepends a Bcc header of its own, above the Received lines,
        // naming envelope recipients; only what follows is what was sent.
        $submitted = substr($source, (int) strpos($source, "\nDate: "));
        self::assertTrue(preg_match('/^Bcc:/mi', $submitted) !== 1, 'The Bcc header is not sent.');
        self::assertSame([self::BOB], array_column($caught['To'] ?? [], 'Address'));
        self::assertSame(['hidden@example.test'], array_column($caught['Bcc'] ?? [], 'Address'), 'The Bcc recipient was in the envelope.');
        self::assertNotContains('<script', quoted_printable_decode($source));
        self::assertContains('Content-Type: text/plain', $source, 'An HTML message carries a text alternative.');

        $contacts = new Preferences($config->string('storage.users'), self::ALICE);
        self::assertSame(['bob@example.test', 'hidden@example.test'], array_column($contacts->contacts(''), 'email'));
        self::assertSame([['name' => 'Bob', 'email' => self::BOB]], $contacts->contacts('bo'));
    }

    public function testInvalidRecipientsAreRefusedBeforeAnythingIsSent(): void
    {
        $config = $this->config();
        $compose = $this->compose($config, $this->mailbox(config: $config));

        self::assertThrows(UserError::class, static fn () => $compose->send(['to' => [['email' => 'not an address']], 'subject' => 'x', 'text' => 'x']), 'not a valid address');
        self::assertThrows(UserError::class, static fn () => $compose->send(['to' => [], 'subject' => 'x', 'text' => 'x']), 'at least one recipient');

        $limited = $this->config(['limits' => ['recipients' => 2]]);
        $compose = $this->compose($limited, $this->mailbox(config: $limited));
        self::assertThrows(UserError::class, static fn () => $compose->send(['to' => 'a@example.test, b@example.test, c@example.test', 'subject' => 'x', 'text' => 'x']), 'at most');
    }

    public function testADraftIsSavedReplacedReopenedAndSentAway(): void
    {
        $config = $this->config();
        $mailbox = $this->mailbox(config: $config);
        $uploads = $this->uploads($config);
        $file = tempnam(sys_get_temp_dir(), 'ms');
        file_put_contents($file, 'attached text');
        $upload = $uploads->store($file, 'notes.txt', 'text/plain');
        $compose = $this->compose($config, $mailbox, $uploads);

        $first = $compose->saveDraft(['to' => self::BOB, 'subject' => 'Draft one', 'html' => '<p>First <img src="data:image/png;base64,iVBORw0KGgo="></p>', 'attachments' => ['u:'.$upload['id']]]);
        $second = $compose->saveDraft(['to' => self::BOB, 'subject' => 'Draft two', 'html' => '<p>Second <img src="data:image/png;base64,iVBORw0KGgo="></p>', 'attachments' => ['u:'.$upload['id']], 'draft' => $first]);

        self::assertSame('Drafts', $second['folder']);
        self::assertSame(['Draft two'], array_column($mailbox->messages('Drafts', 1, 50)['messages'], 'subject'), 'The newer version replaces the older.');

        $reopened = $compose->prefill('draft', 'Drafts', (int) $second['uid'], 'UTC');
        self::assertSame('Draft two', $reopened['subject']);
        self::assertSame([['name' => '', 'email' => self::BOB]], $reopened['to']);
        self::assertSame(['notes.txt'], array_column($reopened['attachments'], 'name'));
        self::assertContains('src="data:image/png;base64,iVBORw0KGgo="', (string) $reopened['html'], 'The embedded image comes back to the editor.');
        self::assertSame($second, $reopened['draft']);

        $compose->send(['to' => self::BOB, 'subject' => 'Draft two', 'html' => $reopened['html'], 'attachments' => array_column($reopened['attachments'], 'id'), 'draft' => $reopened['draft']]);

        self::assertSame(0, $mailbox->messages('Drafts', 1, 50)['total'], 'Sending takes the draft away.');
        $sentUid = $mailbox->messages('Sent', 1, 50)['messages'][0]['uid'];
        self::assertSame(['notes.txt'], array_column($mailbox->message('Sent', $sentUid)['attachments'], 'name'));
    }

    public function testReplyAllAndForwardStartFromTheOriginal(): void
    {
        $config = $this->config();
        $mailbox = $this->mailbox(config: $config);
        $uid = (int) $mailbox->append('INBOX', self::raw(<<<'MAIL'
            From: Bob Builder <bob@example.test>
            To: alice@example.test, carol@example.test
            Cc: dave@example.test
            Reply-To: team@example.test
            Subject: Plan
            Message-ID: <plan@example.test>
            References: <start@example.test>
            Date: Wed, 23 Sep 2026 11:02:00 +0200
            MIME-Version: 1.0
            Content-Type: multipart/mixed; boundary=m

            --m
            Content-Type: text/plain; charset=utf-8

            Here is the plan.
            --m
            Content-Type: application/pdf; name=plan.pdf
            Content-Disposition: attachment; filename=plan.pdf
            Content-Transfer-Encoding: base64

            JVBERi0xLjQK
            --m--

            MAIL), []);
        $compose = $this->compose($config, $mailbox);

        $reply = $compose->prefill('replyAll', 'INBOX', $uid, 'Europe/Budapest');
        self::assertSame('Re: Plan', $reply['subject']);
        self::assertSame(['team@example.test'], array_column($reply['to'], 'email'), 'Reply-To wins over From.');
        self::assertSame(['carol@example.test', 'dave@example.test'], array_column($reply['cc'], 'email'), 'Everyone else, never the mailbox itself.');
        self::assertSame('<plan@example.test>', $reply['inReplyTo']);
        self::assertSame('<start@example.test> <plan@example.test>', $reply['references']);
        self::assertContains('> Here is the plan.', $reply['quoteText']);
        self::assertContains('<blockquote type="cite">', $reply['quoteHtml']);

        $forward = $compose->prefill('forward', 'INBOX', $uid, 'UTC');
        self::assertSame('Fwd: Plan', $forward['subject']);
        self::assertSame([], $forward['to']);
        self::assertSame(['plan.pdf'], array_column($forward['attachments'], 'name'));
        self::assertContains('From: Bob Builder <bob@example.test>', $forward['quoteText']);

        $compose->send(['to' => self::BOB, 'subject' => $forward['subject'], 'text' => "FYI\n\n".$forward['quoteText'], 'attachments' => array_column($forward['attachments'], 'id'), 'source' => $forward['source']]);

        self::assertSame(true, $mailbox->message('INBOX', $uid, false)['flags']['forwarded'], 'The original is marked forwarded.');
        $sent = $mailbox->messages('Sent', 1, 50)['messages'][0];
        self::assertSame(['plan.pdf'], array_column($mailbox->message('Sent', $sent['uid'])['attachments'], 'name'));
    }

    public function testRepliesAreWrittenInTheUsersLanguage(): void
    {
        $config = $this->config();
        $mailbox = $this->mailbox(config: $config);
        $uid = (int) $mailbox->append('INBOX', self::raw("From: Bob <bob@example.test>\nTo: alice@example.test\nSubject: Re: Kérdés\nDate: Wed, 23 Sep 2026 11:02:00 +0200\n\nSzia\n"), []);

        $reply = $this->compose($config, $mailbox, lang: new Lang('hu'))->prefill('reply', 'INBOX', $uid, 'Europe/Budapest');

        self::assertSame('Re: Kérdés', $reply['subject'], 'An existing prefix is not doubled.');
        self::assertContains('időpontban Bob ezt írta:', $reply['quoteText']);
    }

    private function compose(Config $config, Mailbox $mailbox, ?Uploads $uploads = null, ?Lang $lang = null): Compose
    {
        return new Compose(
            $config,
            $mailbox,
            new Preferences($config->string('storage.users'), self::ALICE),
            $uploads ?? $this->uploads($config),
            $lang ?? new Lang('en'),
            self::grant(),
        );
    }

    private function uploads(Config $config): Uploads
    {
        return new Uploads($config->string('storage.uploads'), bin2hex(random_bytes(16)));
    }

    /**
     * @return array<string, mixed>
     */
    private static function waitForCaught(string $subject): array
    {
        for ($i = 0; $i < 30; $i++) {
            foreach (self::caught() as $message) {
                if (Header::decode($message['Subject'] ?? '') === $subject) {
                    return $message;
                }
            }

            usleep(200000);
        }

        throw new AssertionFailed("Mailpit never received \"{$subject}\".");
    }
}
