<?php

declare(strict_types=1);

namespace MailSimply\Mime;

/**
 * Undoes a Content-Transfer-Encoding, either all at once or chunk by chunk as
 * an attachment streams in from the server.
 */
final class Decoder
{
    private string $buffer = '';

    /**
     * @param  callable(string): void  $sink
     */
    public function __construct(private readonly string $encoding, private $sink) {}

    public static function decode(string $data, string $encoding): string
    {
        return match (strtolower(trim($encoding))) {
            'base64' => (string) base64_decode((string) preg_replace('/[^A-Za-z0-9+\/=]/', '', $data)),
            // Line endings stay as they are: a hard CRLF in quoted-printable is
            // part of the data, which for an attachment is not text.
            'quoted-printable' => quoted_printable_decode($data),
            'x-uuencode', 'x-uue', 'uuencode' => self::uudecode($data),
            default => $data,
        };
    }

    /**
     * Take the next chunk of encoded data, passing on whatever of it can be
     * decoded already.
     */
    public function write(string $chunk): void
    {
        $encoding = strtolower(trim($this->encoding));

        if ($encoding === 'base64') {
            $this->buffer .= (string) preg_replace('/[^A-Za-z0-9+\/=]/', '', $chunk);
            $whole = strlen($this->buffer) - strlen($this->buffer) % 4;

            if ($whole > 0) {
                ($this->sink)((string) base64_decode(substr($this->buffer, 0, $whole)));
                $this->buffer = substr($this->buffer, $whole);
            }

            return;
        }

        if ($encoding === 'quoted-printable') {
            // A soft break or an escape may be cut in two; only whole lines
            // are decoded.
            $this->buffer .= $chunk;
            $newline = strrpos($this->buffer, "\n");

            if ($newline !== false) {
                ($this->sink)(self::decode(substr($this->buffer, 0, $newline + 1), $encoding));
                $this->buffer = substr($this->buffer, $newline + 1);
            }

            return;
        }

        ($this->sink)($chunk);
    }

    public function finish(): void
    {
        if ($this->buffer !== '') {
            ($this->sink)(self::decode($this->buffer, $this->encoding));
            $this->buffer = '';
        }
    }

    private static function uudecode(string $data): string
    {
        $lines = preg_split('/\r?\n/', $data) ?: [];
        $body = [];
        $inside = false;

        foreach ($lines as $line) {
            if (! $inside) {
                $inside = str_starts_with($line, 'begin ');

                continue;
            }

            if (trim($line) === 'end') {
                break;
            }

            $body[] = $line;
        }

        $decoded = convert_uudecode(implode("\n", $body)."\n");

        return $decoded === false ? '' : $decoded;
    }
}
