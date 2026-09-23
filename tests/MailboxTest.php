<?php

declare(strict_types=1);

namespace MailSimply\Tests;

use MailSimply\Imap\AuthenticationFailed;
use MailSimply\Imap\Client;
use MailSimply\Mailbox;
use MailSimply\UserError;

/**
 * The mailbox against a real Dovecot: see tests/dovecot.
 */
final class MailboxTest extends TestCase
{
    private const string INVOICE = <<<'MAIL'
        From: =?UTF-8?Q?Kov=C3=A1cs_J=C3=A1nos?= <janos@example.hu>
        To: alice@example.test
        Subject: =?UTF-8?Q?Sz=C3=A1mla_szeptember?=
        Date: Tue, 22 Sep 2026 16:40:00 +0200
        Message-ID: <invoice@example.hu>
        List-Unsubscribe: <mailto:unsub@example.hu>, <https://example.hu/unsub>
        MIME-Version: 1.0
        Content-Type: multipart/mixed; boundary=m

        --m
        Content-Type: multipart/related; boundary=r

        --r
        Content-Type: text/html; charset=iso-8859-2
        Content-Transfer-Encoding: quoted-printable

        <p>mell=E9kelve a sz=E1mla</p><img src=3D"cid:logo@x"><img src=3D"https://track.example/px.gif"><script>alert(1)</script>
        --r
        Content-Type: image/png
        Content-ID: <logo@x>
        Content-Transfer-Encoding: base64

        iVBORw0KGgo=
        --r--
        --m
        Content-Type: application/pdf; name="szamla.pdf"
        Content-Disposition: attachment; filename*=UTF-8''sz%C3%A1mla.pdf
        Content-Transfer-Encoding: base64

        JVBERi0xLjQKJcTl8uXrp/Og0MTGCg==
        --m--

        MAIL;

    public function testTheMasterUserOpensTheMailboxWithoutItsPassword(): void
    {
        $mailbox = $this->mailbox();

        self::assertSame(['inbox', 'drafts', 'sent', 'junk', 'trash'], array_column($mailbox->folders(), 'role'));
        self::assertTrue($mailbox->quota() !== null, 'The quota root is reported.');
    }

    public function testThePasswordOpensTheMailboxAndAWrongOneDoesNot(): void
    {
        $config = $this->config();
        $options = Mailbox::serverOptions($config, 'imap');

        $imap = Client::connect($options);
        $imap->authenticate(self::ALICE, self::ALICE_PASSWORD);
        $imap->logout();

        self::assertThrows(AuthenticationFailed::class, static function () use ($options): void {
            $imap = Client::connect($options);
            $imap->authenticate(self::ALICE, 'wrong');
        });

        $grant = ['address' => self::ALICE, 'name' => '', 'master' => false, 'password' => 'wrong'];
        self::assertThrows(UserError::class, static fn () => Mailbox::open($config, $grant), 'did not accept');
    }

    public function testAMessageIsListedReadAndRendered(): void
    {
        $mailbox = $this->mailbox();
        $uid = $mailbox->append('INBOX', self::raw(self::INVOICE), []);

        $page = $mailbox->messages('INBOX', 1, 50);
        self::assertSame(1, $page['total']);
        self::assertSame('Számla szeptember', $page['messages'][0]['subject']);
        self::assertSame([['name' => 'Kovács János', 'email' => 'janos@example.hu']], $page['messages'][0]['from']);
        self::assertSame(false, $page['messages'][0]['flags']['seen']);
        self::assertSame(true, $page['messages'][0]['attachments']);

        $message = $mailbox->message('INBOX', (int) $uid);
        self::assertSame(true, $message['flags']['seen'], 'Opening a message marks it read.');
        self::assertSame('https://example.hu/unsub', $message['unsubscribe']);
        self::assertSame([['part' => '2', 'name' => 'számla.pdf', 'type' => 'application/pdf', 'size' => 24, 'image' => false]], $message['attachments']);

        $body = $mailbox->body('INBOX', (int) $uid, false, static fn (string $section): string => 'part:'.$section);
        self::assertContains('<p>mellékelve a számla</p>', $body['html']);
        self::assertContains('<img src="part:1.2">', $body['html']);
        self::assertNotContains('<script', $body['html']);
        self::assertNotContains('track.example', $body['html']);
        self::assertSame(1, $body['blocked']);

        $pdf = '';
        $mailbox->streamPart((int) $uid, '2', 'base64', static function (string $chunk) use (&$pdf): void {
            $pdf .= $chunk;
        });
        self::assertTrue(str_starts_with($pdf, '%PDF-1.4'));
    }

    public function testSearchFiltersAndPages(): void
    {
        $mailbox = $this->mailbox();

        for ($i = 1; $i <= 12; $i++) {
            $mailbox->append('INBOX', self::raw("From: sender{$i}@example.com\nTo: alice@example.test\nSubject: Report {$i}".($i === 7 ? ' árvíztűrő' : '')."\nDate: Mon, {$i} Sep 2026 10:00:00 +0000\n\nBody {$i}\n"), $i % 3 === 0 ? ['\\Seen'] : []);
        }

        $first = $mailbox->messages('INBOX', 1, 5);
        self::assertSame(12, $first['total']);
        self::assertSame(3, $first['pages']);
        self::assertSame(['Report 12', 'Report 11', 'Report 10', 'Report 9', 'Report 8'], array_column($first['messages'], 'subject'), 'Newest first.');
        self::assertSame(['Report 2', 'Report 1'], array_column($mailbox->messages('INBOX', 3, 5)['messages'], 'subject'));

        self::assertSame(['Report 7 árvíztűrő'], array_column($mailbox->messages('INBOX', 1, 50, ['query' => 'árvíztűrő'])['messages'], 'subject'), 'A UTF-8 search.');
        self::assertSame(['Report 5'], array_column($mailbox->messages('INBOX', 1, 50, ['query' => 'sender5@'])['messages'], 'subject'));
        self::assertSame(8, $mailbox->messages('INBOX', 1, 50, ['unread' => true])['total']);
        self::assertSame(['Report 12'], array_column($mailbox->messages('INBOX', 1, 50, ['query' => 'Body 12', 'body' => true])['messages'], 'subject'));
    }

    public function testFlagsMovesAndDeletes(): void
    {
        $mailbox = $this->mailbox();
        $a = (int) $mailbox->append('INBOX', self::raw("Subject: A\n\na\n"), []);
        $b = (int) $mailbox->append('INBOX', self::raw("Subject: B\n\nb\n"), []);

        $mailbox->flag('INBOX', [$a], 'flagged', true);
        $mailbox->flag('INBOX', [$a, $b], 'seen', true);
        $mailbox->flag('INBOX', [$b], 'seen', false);
        $flags = array_column($mailbox->messages('INBOX', 1, 50)['messages'], 'flags', 'uid');
        self::assertSame([true, false], [$flags[$a]['flagged'], $flags[$b]['flagged']]);
        self::assertSame([true, false], [$flags[$a]['seen'], $flags[$b]['seen']]);
        self::assertSame(1, $mailbox->messages('INBOX', 1, 50, ['unread' => true])['total']);

        $mailbox->markAllRead('INBOX');
        self::assertSame(0, $mailbox->messages('INBOX', 1, 50, ['unread' => true])['total']);

        self::assertSame(false, $mailbox->delete('INBOX', [$a]), 'Deleting from the inbox moves to the trash.');
        self::assertSame(['A'], array_column($mailbox->messages('Trash', 1, 50)['messages'], 'subject'));

        $mailbox->move('INBOX', [$b], 'Junk');
        self::assertSame(0, $mailbox->messages('INBOX', 1, 50)['total']);

        $junkUid = $mailbox->messages('Junk', 1, 50)['messages'][0]['uid'];
        self::assertSame(true, $mailbox->delete('Junk', [$junkUid]), 'Deleting from junk is for good.');
        self::assertSame(0, $mailbox->messages('Junk', 1, 50)['total']);

        self::assertThrows(UserError::class, fn () => $mailbox->empty('INBOX'), 'Only the trash');
        $mailbox->empty('Trash');
        self::assertSame(0, $mailbox->messages('Trash', 1, 50)['total']);
    }

    public function testFoldersAreCreatedRenamedAndDeleted(): void
    {
        $mailbox = $this->mailbox();

        $id = $mailbox->createFolder('Ügyfelek', null);
        self::assertSame('&ANw-gyfelek', $id, 'Stored in modified UTF-7.');

        $child = $mailbox->createFolder('2026', $id);
        $folders = array_column($mailbox->folders(), null, 'id');
        self::assertSame('Ügyfelek', $folders[$id]['name']);
        self::assertSame($id, $folders[$child]['parent']);
        self::assertSame(1, $folders[$child]['depth']);

        self::assertThrows(UserError::class, fn () => $mailbox->createFolder('Ügyfelek', null), 'already exists');
        self::assertThrows(UserError::class, fn () => $mailbox->createFolder('a/b', null));
        self::assertThrows(UserError::class, fn () => $mailbox->deleteFolder($id), 'inside this one');
        self::assertThrows(UserError::class, fn () => $mailbox->renameFolder('Sent', 'Other'), 'cannot be renamed');

        $renamed = $mailbox->renameFolder($child, 'Archív');
        self::assertSame('Archív', array_column($mailbox->folders(), 'name', 'id')[$renamed]);

        $mailbox->deleteFolder($renamed);
        $mailbox->deleteFolder($id);
        self::assertSame(['INBOX', 'Drafts', 'Sent', 'Junk', 'Trash'], array_column($mailbox->folders(), 'id'));
    }

    public function testUnknownFoldersAndMessagesAreUserErrors(): void
    {
        $mailbox = $this->mailbox();

        self::assertThrows(UserError::class, fn () => $mailbox->messages('Nope', 1, 50), 'no longer exists');
        self::assertThrows(UserError::class, fn () => $mailbox->message('INBOX', 999), 'no longer exists');
        self::assertThrows(UserError::class, fn () => $mailbox->part('INBOX', 999, '1'), 'no longer exists');
    }
}
