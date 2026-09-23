<?php

declare(strict_types=1);

namespace MailSimply\Tests;

use MailSimply\Imap\Client;
use MailSimply\Imap\Parser;
use MailSimply\Mime\Address;
use MailSimply\Mime\Builder;
use MailSimply\Mime\Charset;
use MailSimply\Mime\Decoder;
use MailSimply\Mime\Header;
use MailSimply\Mime\Layout;
use MailSimply\Mime\Part;

/**
 * Reading and writing messages, and reading what IMAP says about them. No
 * mail server needed.
 */
final class MimeTest extends TestCase
{
    public function testParserReadsListsStringsLiteralsAndSections(): void
    {
        $values = Parser::parse("* 3 FETCH (UID 7 FLAGS (\\Seen \$Forwarded) BODY[HEADER.FIELDS (FROM TO)] {8}\r\nab)(\r\n\"c ENVELOPE (NIL \"a \\\"b\\\"\" NIL))\r\n");

        self::assertSame(['*', '3', 'FETCH'], array_slice($values, 0, 3));

        $pairs = Parser::pairs($values[3]);
        self::assertSame('7', $pairs['UID']);
        self::assertSame(['\\Seen', '$Forwarded'], $pairs['FLAGS']);
        // The literal carries parentheses, a CRLF and a quote untouched.
        self::assertSame("ab)(\r\n\"c", $pairs['BODY[HEADER.FIELDS (FROM TO)]']);
        self::assertSame([null, 'a "b"', null], $pairs['ENVELOPE']);
    }

    public function testParserDropsPartialRangesFromFetchNames(): void
    {
        $pairs = Parser::pairs(Parser::parse('(BODY[1.2]<0> "text")')[0]);

        self::assertSame('text', $pairs['BODY[1.2]']);
    }

    public function testUidSetsCollapseRuns(): void
    {
        self::assertSame('1:3,7,9:10', Client::set([10, 3, 1, 2, 9, 7, 7]));
        self::assertSame('5', Client::set([5]));
    }

    public function testEncodedWordsAreJoinedAndDecoded(): void
    {
        self::assertSame('árvíztűrő tükörfúrógép end', Header::decode('=?UTF-8?B?w6FydsOtenTFsXLFkQ==?= =?UTF-8?Q?_t=C3=BCk=C3=B6rf=C3=BAr=C3=B3g=C3=A9p?= end'));
        self::assertSame('árvíűrő', Header::decode('=?iso-8859-2?Q?=E1rv=ED=FBr=F5?='));
        self::assertSame('őű', Header::decode('=?windows-1250?Q?=F5=FB?='));
        // A multi-byte character split across two words of one charset.
        self::assertSame('é', Header::decode('=?UTF-8?B?ww==?= =?UTF-8?B?qQ==?='));
        // Raw 8-bit bytes in a header, as some senders write them.
        self::assertSame('Größe', Header::decode("Gr\xF6\xDFe"));
    }

    public function testParametersFollowRfc2231(): void
    {
        [$main, $parameters] = Header::parameters("attachment; filename*0*=UTF-8''%C3%A1rv; filename*1=.pdf; size=3");

        self::assertSame('attachment', $main);
        self::assertSame('árv.pdf', $parameters['filename']);
        self::assertSame('3', $parameters['size']);

        [, $quoted] = Header::parameters('inline; filename="a \"b\" c.txt"');
        self::assertSame('a "b" c.txt', $quoted['filename']);
    }

    public function testAddressListsParseNamesGroupsAndComments(): void
    {
        $addresses = Address::parseList('"Doe, Jane" <jane@example.com>, bob@example.com (Bob), undisclosed-recipients:;, =?UTF-8?B?w4Fkw6Ft?= <adam@x.hu>, Team: a@b.co, c@d.co;');

        self::assertSame([
            ['name' => 'Doe, Jane', 'email' => 'jane@example.com'],
            ['name' => 'Bob', 'email' => 'bob@example.com'],
            ['name' => 'Ádám', 'email' => 'adam@x.hu'],
            ['name' => '', 'email' => 'a@b.co'],
            ['name' => '', 'email' => 'c@d.co'],
        ], $addresses);
    }

    public function testAddressValidationRefusesWhatNobodyTypes(): void
    {
        self::assertTrue(Address::isValid('info@example.com'));
        self::assertTrue(Address::isValid('first.last+tag@sub.example.co.uk'));
        self::assertTrue(Address::isValid('user@bücher.de'));
        self::assertTrue(! Address::isValid('no-at-sign'));
        self::assertTrue(! Address::isValid('a@b'));
        self::assertTrue(! Address::isValid('"quoted"@example.com'));
        self::assertTrue(! Address::isValid("a@example.com\r\nBcc: x@y.z"));
        self::assertTrue(! Address::isValid('a b@example.com'));
    }

    public function testAddressFormattingEncodesAndQuotes(): void
    {
        self::assertSame('plain@example.com', Address::format(['name' => '', 'email' => 'plain@example.com']));
        self::assertSame('"Doe, Jane" <jane@example.com>', Address::format(['name' => 'Doe, Jane', 'email' => 'jane@example.com']));
        self::assertSame('=?UTF-8?B?w4Fkw6Ft?= <adam@x.hu>', Address::format(['name' => 'Ádám', 'email' => 'adam@x.hu']));
        self::assertSame('user@xn--bcher-kva.de', Address::format(['name' => '', 'email' => 'user@bücher.de']));
        self::assertSame('Ádám <adam@x.hu>, b@c.de', Address::display([['name' => 'Ádám', 'email' => 'adam@x.hu'], ['name' => '', 'email' => 'b@c.de']]));
    }

    public function testCharsetsFallBackInsteadOfFailing(): void
    {
        self::assertSame('“hi”', Charset::toUtf8("\x93hi\x94", 'iso-8859-1'));
        self::assertSame('őű', Charset::toUtf8("\xF5\xFB", 'windows-1250'));
        self::assertSame('abc', Charset::toUtf8('abc', 'x-bogus'));
        self::assertTrue(mb_check_encoding(Charset::toUtf8("\xff\xfe", 'utf-8'), 'UTF-8'));
        self::assertSame('é', Charset::toUtf8("\xE9", null));
    }

    public function testStreamingDecoderMatchesTheOneShot(): void
    {
        $data = random_bytes(10000);

        foreach (['base64' => chunk_split(base64_encode($data), 76, "\r\n"), 'quoted-printable' => quoted_printable_encode($data)] as $encoding => $encoded) {
            $out = '';
            $decoder = new Decoder($encoding, static function (string $chunk) use (&$out): void {
                $out .= $chunk;
            });

            foreach (str_split($encoded, 777) as $chunk) {
                $decoder->write($chunk);
            }

            $decoder->finish();

            self::assertSame(bin2hex($data), bin2hex($out), $encoding);
            self::assertSame(bin2hex($data), bin2hex(Decoder::decode($encoded, $encoding)), $encoding);
        }
    }

    public function testBodyStructureNumbersSectionsTheImapWay(): void
    {
        // multipart/mixed (multipart/alternative (text/plain, text/html), application/pdf, message/rfc822)
        $structure = Parser::parse('((("text" "plain" ("charset" "utf-8") NIL NIL "7bit" 10 1 NIL NIL NIL NIL)("text" "html" ("charset" "utf-8") NIL NIL "quoted-printable" 20 1 NIL NIL NIL NIL) "alternative" ("boundary" "a") NIL NIL NIL)("application" "pdf" ("name" "x.pdf") NIL NIL "base64" 400 NIL ("attachment" ("filename*" "UTF-8\'\'sz%C3%A1mla.pdf")) NIL NIL)("message" "rfc822" NIL NIL NIL "7bit" 300 (NIL "Inner" NIL NIL NIL NIL NIL NIL NIL NIL) ("text" "plain" ("charset" "us-ascii") NIL NIL "7bit" 5 1 NIL NIL NIL NIL) 8 NIL NIL NIL NIL) "mixed" ("boundary" "m") NIL NIL NIL))')[0];

        $root = Part::fromStructure($structure);
        self::assertSame('multipart', $root->type);
        self::assertSame('1', $root->parts[0]->section);
        self::assertSame('1.1', $root->parts[0]->parts[0]->section);
        self::assertSame('1.2', $root->parts[0]->parts[1]->section);
        self::assertSame('2', $root->parts[1]->section);
        self::assertSame('számla.pdf', $root->parts[1]->filename());
        self::assertSame('3', $root->parts[2]->section);
        self::assertSame('3.1', $root->parts[2]->parts[0]->section);

        $layout = new Layout($root);
        self::assertSame(['1.2'], array_map(static fn (Part $part): string => $part->section, $layout->body));
        self::assertSame(['2', '3'], array_map(static fn (Part $part): string => $part->section, $layout->attachments));

        $plain = new Layout($root, false);
        self::assertSame(['1.1'], array_map(static fn (Part $part): string => $part->section, $plain->body));
    }

    public function testSinglePartMessagesHaveTheirBodyAtSectionOne(): void
    {
        $root = Part::fromStructure(Parser::parse('("text" "plain" ("charset" "iso-8859-2" "format" "flowed") NIL NIL "quoted-printable" 42 3 NIL NIL NIL NIL)')[0]);

        self::assertSame('1', $root->section);
        self::assertSame('iso-8859-2', $root->charset());
        self::assertSame(['1'], array_map(static fn (Part $part): string => $part->section, (new Layout($root))->body));
    }

    public function testRelatedImagesAreInlineNotAttachments(): void
    {
        $root = Part::fromStructure(Parser::parse('((("text" "html" ("charset" "utf-8") NIL NIL "7bit" 20 1 NIL NIL NIL NIL)("image" "png" NIL "<logo@x>" NIL "base64" 100 NIL ("inline" NIL) NIL NIL) "related" ("boundary" "r") NIL NIL NIL)("image" "jpeg" ("name" "photo.jpg") "<photo@x>" NIL "base64" 900 NIL ("attachment" ("filename" "photo.jpg")) NIL NIL)("application" "pgp-signature" NIL NIL NIL "7bit" 50 NIL NIL NIL NIL) "mixed" ("boundary" "m") NIL NIL NIL)')[0]);
        $layout = new Layout($root);

        self::assertSame(['1.1'], array_map(static fn (Part $part): string => $part->section, $layout->body));
        self::assertSame(['2'], array_map(static fn (Part $part): string => $part->section, $layout->attachments), 'The related image and the signature are not attachments.');
        self::assertSame('1.2', $layout->inline['logo@x']->section);
    }

    public function testBuilderWritesAParsableMessage(): void
    {
        $builder = (new Builder(
            ['name' => 'Kovács János', 'email' => 'janos@example.hu'],
            [['name' => 'Doe, Jane', 'email' => 'jane@example.com']],
            [['name' => '', 'email' => 'cc@example.com']],
            [['name' => '', 'email' => 'hidden@example.com']],
            'Számla ✓ '.str_repeat('hosszú tárgy ', 8),
            "Line one\n.\nLine three",
            '<p>Hi <b>there</b> <img src="data:image/png;base64,iVBORw0KGgo="></p>',
        ))->header('In-Reply-To', "<a@b>\r\nBcc: evil@example.com")->attach('szám la.pdf', 'application/pdf', '%PDF');

        $sent = $builder->build(false);
        $kept = $builder->build(true);

        self::assertTrue(preg_match('/^Bcc:/mi', $sent) !== 1, 'No Bcc header is sent.');
        self::assertNotContains('hidden@example.com', $sent);
        self::assertContains('Bcc: hidden@example.com', $kept);
        self::assertSame(['jane@example.com', 'cc@example.com', 'hidden@example.com'], $builder->recipients());

        // A header value cannot open a header of its own: the line break is
        // gone, and what followed it stays inside In-Reply-To.
        self::assertContains('In-Reply-To: <a@b>  Bcc: evil@example.com', $sent);

        foreach (explode("\r\n", $sent) as $line) {
            self::assertTrue(strlen($line) <= 998, 'Lines stay within the SMTP limit.');
            self::assertTrue($line !== '.', 'No line is a lone dot.');
        }

        self::assertTrue(! preg_match("/(?<!\r)\n/", $sent), 'Every line ends in CRLF.');

        [$head] = explode("\r\n\r\n", $sent, 2);
        $headers = Header::parse($head);
        self::assertSame('Számla ✓ '.str_repeat('hosszú tárgy ', 8), Header::decode($headers['subject'][0]));
        self::assertSame([['name' => 'Doe, Jane', 'email' => 'jane@example.com']], Address::parseList($headers['to'][0]));
        self::assertSame([['name' => 'Kovács János', 'email' => 'janos@example.hu']], Address::parseList($headers['from'][0]));
        self::assertContains('multipart/mixed', $headers['content-type'][0]);

        // The pasted image became a part of its own, referenced by cid:.
        self::assertContains('multipart/related', $sent);
        self::assertContains('Content-ID: <', $sent);
        self::assertTrue(preg_match('/src=3D"cid:[0-9a-f]+@mail-simply"/', $sent) === 1, 'The HTML refers to the image by cid:.');
        self::assertContains("filename*=UTF-8''sz%C3%A1m%20la.pdf", $sent);
    }
}
