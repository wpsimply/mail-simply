<?php

declare(strict_types=1);

namespace MailSimply\Mime;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Writes an outgoing message: headers, a plain-text body (and an HTML one
 * beside it when there is one), images embedded in the HTML, and attachments.
 *
 * The layout is the conventional one every client reads:
 *
 *   multipart/mixed                 only with attachments
 *     multipart/related             only with embedded images
 *       multipart/alternative       only with an HTML body
 *         text/plain
 *         text/html
 *       image/png                   Content-ID, referenced as cid: by the HTML
 *     application/pdf               attachments
 *
 * Text goes quoted-printable, everything else base64, and every line ends in
 * CRLF.
 */
final class Builder
{
    private const string EOL = "\r\n";

    /** @var list<array{name: string, type: string, data: string, cid: ?string}> */
    private array $attachments = [];

    /** @var list<array{name: string, type: string, data: string, cid: string}> */
    private array $embedded = [];

    /** @var array<string, string> */
    private array $headers = [];

    /**
     * @param  array{name: string, email: string}  $from
     * @param  list<array{name: string, email: string}>  $to
     * @param  list<array{name: string, email: string}>  $cc
     * @param  list<array{name: string, email: string}>  $bcc
     */
    public function __construct(
        private readonly array $from,
        private readonly array $to,
        private readonly array $cc,
        private readonly array $bcc,
        private readonly string $subject,
        private readonly string $text,
        private ?string $html = null,
        private readonly ?DateTimeInterface $date = null,
        private ?string $messageId = null,
    ) {}

    public function attach(string $name, string $type, string $data): self
    {
        $this->attachments[] = ['name' => $name, 'type' => self::safeType($type), 'data' => $data, 'cid' => null];

        return $this;
    }

    /**
     * Add a header of the caller's own, e.g. In-Reply-To. Line breaks are
     * stripped, so a value cannot smuggle in a header of its own.
     */
    public function header(string $name, string $value): self
    {
        $value = trim(str_replace(["\r", "\n"], ' ', $value));

        if ($value !== '' && preg_match('/^[A-Za-z0-9-]+$/', $name) === 1) {
            $this->headers[$name] = $value;
        }

        return $this;
    }

    public function messageId(): string
    {
        if ($this->messageId === null) {
            $at = strrpos($this->from['email'], '@');
            $domain = $at === false ? 'localhost' : Address::asciiDomain(substr($this->from['email'], $at + 1));
            $this->messageId = '<'.bin2hex(random_bytes(12)).'@'.$domain.'>';
        }

        return $this->messageId;
    }

    /**
     * Every address the message goes to, Bcc included.
     *
     * @return list<string>
     */
    public function recipients(): array
    {
        $recipients = [];

        foreach ([...$this->to, ...$this->cc, ...$this->bcc] as $address) {
            $recipients[strtolower($address['email'])] = Address::asciiDomain($address['email']);
        }

        return array_values($recipients);
    }

    /**
     * The message as it is sent (without a Bcc header) or as it is kept in a
     * folder (with one, so a draft or the sent copy still shows it).
     */
    public function build(bool $withBcc = false): string
    {
        $this->extractEmbeddedImages();

        $headers = [
            'Date' => ($this->date ?? new DateTimeImmutable)->format(DateTimeInterface::RFC2822),
            'From' => Address::format($this->from),
        ];

        if ($this->to !== []) {
            $headers['To'] = Address::formatList($this->to);
        }

        if ($this->cc !== []) {
            $headers['Cc'] = Address::formatList($this->cc);
        }

        if ($withBcc && $this->bcc !== []) {
            $headers['Bcc'] = Address::formatList($this->bcc);
        }

        $headers['Subject'] = Header::encode($this->subject);
        $headers['Message-ID'] = $this->messageId();

        foreach ($this->headers as $name => $value) {
            $headers[$name] = $value;
        }

        $headers['MIME-Version'] = '1.0';
        $headers['User-Agent'] = 'Mail Simply';

        [$contentHeaders, $body] = $this->body();

        $output = '';

        foreach ([...$headers, ...$contentHeaders] as $name => $value) {
            $output .= (str_contains($value, "\r\n") ? $name.': '.$value : Header::fold($name, $value)).self::EOL;
        }

        return $output.self::EOL.$body;
    }

    /**
     * @return array{0: array<string, string>, 1: string}
     */
    private function body(): array
    {
        $part = $this->textPart('plain', $this->text);

        if ($this->html !== null) {
            $part = $this->multipart('alternative', [$part, $this->textPart('html', $this->html)]);
        }

        if ($this->embedded !== []) {
            $parts = [$part];

            foreach ($this->embedded as $image) {
                $parts[] = $this->filePart($image['name'], $image['type'], $image['data'], 'inline', $image['cid']);
            }

            $part = $this->multipart('related', $parts, ['type' => 'multipart/alternative']);
        }

        if ($this->attachments !== []) {
            $parts = [$part];

            foreach ($this->attachments as $attachment) {
                $parts[] = $this->filePart($attachment['name'], $attachment['type'], $attachment['data'], 'attachment', null);
            }

            $part = $this->multipart('mixed', $parts);
        }

        return $part;
    }

    /**
     * @return array{0: array<string, string>, 1: string}
     */
    private function textPart(string $subtype, string $text): array
    {
        $text = (string) preg_replace('/\r\n?|\n/', "\n", $text);
        $encoded = quoted_printable_encode(str_replace("\n", self::EOL, $text));

        // A line of just "." would end the SMTP transfer early at a server
        // that does not dot-stuff; encoding it keeps the text intact anyway.
        $encoded = (string) preg_replace('/^\./m', '=2E', $encoded);

        return [[
            'Content-Type' => 'text/'.$subtype.'; charset=UTF-8',
            'Content-Transfer-Encoding' => 'quoted-printable',
        ], $encoded.self::EOL];
    }

    /**
     * @return array{0: array<string, string>, 1: string}
     */
    private function filePart(string $name, string $type, string $data, string $disposition, ?string $cid): array
    {
        $headers = [
            'Content-Type' => $type.";\r\n ".Header::parameter('name', $name),
            'Content-Transfer-Encoding' => 'base64',
            'Content-Disposition' => $disposition.";\r\n ".Header::parameter('filename', $name),
        ];

        if ($cid !== null) {
            $headers['Content-ID'] = '<'.$cid.'>';
        }

        return [$headers, chunk_split(base64_encode($data), 76, self::EOL)];
    }

    /**
     * @param  list<array{0: array<string, string>, 1: string}>  $parts
     * @param  array<string, string>  $parameters
     * @return array{0: array<string, string>, 1: string}
     */
    private function multipart(string $subtype, array $parts, array $parameters = []): array
    {
        $boundary = '=_'.bin2hex(random_bytes(12));
        $body = '';

        foreach ($parts as [$headers, $content]) {
            $body .= '--'.$boundary.self::EOL;

            foreach ($headers as $name => $value) {
                $body .= $name.': '.$value.self::EOL;
            }

            $body .= self::EOL.$content;

            if (! str_ends_with($content, self::EOL)) {
                $body .= self::EOL;
            }
        }

        $body .= '--'.$boundary.'--'.self::EOL;
        $type = 'multipart/'.$subtype.';';

        foreach ($parameters as $name => $value) {
            $type .= "\r\n ".$name.'="'.$value.'";';
        }

        return [['Content-Type' => $type."\r\n boundary=\"".$boundary.'"'], $body];
    }

    /**
     * Images pasted into the editor arrive as data: URLs inside the HTML.
     * They travel as parts of their own, referenced by Content-ID, which is
     * what every client knows how to show.
     */
    private function extractEmbeddedImages(): void
    {
        if ($this->html === null || $this->embedded !== [] || ! str_contains($this->html, 'data:image/')) {
            return;
        }

        $this->html = (string) preg_replace_callback(
            '/(<img\b[^>]*?\bsrc\s*=\s*)(["\'])data:(image\/(?:png|jpeg|gif|webp));base64,([A-Za-z0-9+\/=\s]+)\2/i',
            function (array $match): string {
                $data = base64_decode((string) preg_replace('/\s+/', '', $match[4]), true);

                if ($data === false) {
                    return $match[0];
                }

                $cid = bin2hex(random_bytes(8)).'@mail-simply';
                $extension = explode('/', strtolower($match[3]))[1];
                $this->embedded[] = [
                    'name' => 'image-'.(count($this->embedded) + 1).'.'.($extension === 'jpeg' ? 'jpg' : $extension),
                    'type' => strtolower($match[3]),
                    'data' => $data,
                    'cid' => $cid,
                ];

                return $match[1].$match[2].'cid:'.$cid.$match[2];
            },
            $this->html,
        );
    }

    private static function safeType(string $type): string
    {
        $type = strtolower(trim($type));

        return preg_match('/^[a-z0-9][a-z0-9!#$&^_.+-]*\/[a-z0-9][a-z0-9!#$&^_.+-]*$/', $type) === 1 ? $type : 'application/octet-stream';
    }
}
