<?php

declare(strict_types=1);

namespace MailSimply\Mime;

/**
 * Reading and writing message headers.
 *
 * Reading follows RFC 5322 unfolding, RFC 2047 encoded words and RFC 2231
 * parameter continuations, and is forgiving the way mail has to be: an
 * encoded word inside a quoted name, raw 8-bit text in a header, or a
 * parameter value left unquoted are all read as their sender meant them.
 */
final class Header
{
    /**
     * Split a header block into name => list of values, names lower-cased,
     * values unfolded but not yet decoded.
     *
     * @return array<string, list<string>>
     */
    public static function parse(string $block): array
    {
        $headers = [];
        $unfolded = preg_replace('/\r?\n[ \t]+/', ' ', rtrim($block, "\r\n")) ?? '';

        foreach (preg_split('/\r?\n/', $unfolded) ?: [] as $line) {
            $colon = strpos($line, ':');

            if ($colon === false || $colon === 0) {
                continue;
            }

            $name = strtolower(trim(substr($line, 0, $colon)));
            $headers[$name][] = trim(substr($line, $colon + 1));
        }

        return $headers;
    }

    /**
     * Decode a header value to UTF-8 text: its encoded words, and any raw
     * 8-bit bytes left in it.
     */
    public static function decode(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (! str_contains($value, '=?')) {
            return Charset::toUtf8($value, null);
        }

        // Whitespace between two adjacent encoded words is not part of the
        // text (RFC 2047 §6.2); anywhere else it is.
        $value = (string) preg_replace('/(=\?[^?\s]+\?[BbQq]\?[^?\s]*\?=)\s+(?==\?[^?\s]+\?[BbQq]\?)/', '$1', $value);

        // Adjacent words in one charset are joined before decoding, because
        // senders split a multi-byte character across two of them.
        $decoded = '';
        $pending = null;
        $offset = 0;

        preg_match_all('/=\?([^?\s]+)\?([BbQq])\?([^?\s]*)\?=/', $value, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($matches as $match) {
            [$whole, $start] = $match[0];
            $between = substr($value, $offset, $start - $offset);
            $charset = strtolower((string) preg_replace('/\*.*$/', '', $match[1][0]));
            $bytes = strtoupper($match[2][0]) === 'B'
                ? (string) base64_decode($match[3][0])
                : quoted_printable_decode(str_replace('_', ' ', $match[3][0]));

            if ($between !== '' || $pending === null || $pending[0] !== $charset) {
                if ($pending !== null) {
                    $decoded .= Charset::toUtf8($pending[1], $pending[0]);
                    $pending = null;
                }

                $decoded .= Charset::toUtf8($between, null);
            }

            $pending = $pending === null ? [$charset, $bytes] : [$charset, $pending[1].$bytes];
            $offset = $start + strlen($whole);
        }

        if ($pending !== null) {
            $decoded .= Charset::toUtf8($pending[1], $pending[0]);
        }

        return $decoded.Charset::toUtf8(substr($value, $offset), null);
    }

    /**
     * Split a structured value into its main part and its parameters, e.g.
     * `attachment; filename*=UTF-8''%C3%A1.pdf` into "attachment" and
     * ["filename" => "á.pdf"].
     *
     * @return array{0: string, 1: array<string, string>}
     */
    public static function parameters(string $value): array
    {
        $parts = self::splitOutsideQuotes($value, ';');
        $main = strtolower(trim((string) array_shift($parts)));
        $raw = [];

        foreach ($parts as $part) {
            $equals = strpos($part, '=');

            if ($equals === false) {
                continue;
            }

            $name = strtolower(trim(substr($part, 0, $equals)));
            $raw[$name] = self::unquote(trim(substr($part, $equals + 1)));
        }

        return [$main, self::combine($raw)];
    }

    /**
     * Resolve RFC 2231 continuations and charsets in parameters, as they come
     * from a header or from BODYSTRUCTURE: `name*0*`, `name*1`, `name*`.
     *
     * @param  array<string, string>  $raw  lower-cased names
     * @return array<string, string>
     */
    public static function combine(array $raw): array
    {
        $plain = [];
        $continued = [];

        foreach ($raw as $name => $value) {
            if (preg_match('/^([^*]+)\*(\d+)?(\*)?$/', $name, $match) === 1) {
                $index = isset($match[2]) && $match[2] !== '' ? (int) $match[2] : 0;
                $continued[$match[1]][$index] = [$value, ($match[3] ?? '') === '*' || ($match[2] ?? '') === ''];

                continue;
            }

            $plain[$name] = self::decode($value);
        }

        foreach ($continued as $name => $sections) {
            ksort($sections);
            $charset = null;
            $bytes = '';

            foreach ($sections as $index => [$value, $encoded]) {
                if ($encoded && $index === array_key_first($sections) && preg_match("/^([^']*)'[^']*'(.*)$/s", $value, $match) === 1) {
                    $charset = $match[1] !== '' ? $match[1] : null;
                    $value = $match[2];
                }

                $bytes .= $encoded ? rawurldecode($value) : $value;
            }

            // The extended form wins over a plain one of the same name: it is
            // the one that can carry the real characters.
            $plain[$name] = Charset::toUtf8($bytes, $charset);
        }

        return $plain;
    }

    /**
     * Encode a text for a header: unchanged when it is printable ASCII,
     * encoded words otherwise, each short enough to fold between.
     */
    public static function encode(string $text): string
    {
        if (preg_match('/^[\x20-\x7e]*$/', $text) === 1 && ! str_contains($text, '=?')) {
            return $text;
        }

        $words = [];
        $chunk = '';

        // 45 bytes of text is 60 of base64, which with the charset wrapper
        // keeps each word under the 75 RFC 2047 allows.
        foreach (mb_str_split($text, 1, 'UTF-8') as $char) {
            if (strlen($chunk.$char) > 45) {
                $words[] = '=?UTF-8?B?'.base64_encode($chunk).'?=';
                $chunk = '';
            }

            $chunk .= $char;
        }

        if ($chunk !== '') {
            $words[] = '=?UTF-8?B?'.base64_encode($chunk).'?=';
        }

        return implode("\r\n ", $words);
    }

    /**
     * A display name for an address header: encoded where it has to be,
     * quoted where its punctuation would otherwise be read as syntax.
     */
    public static function phrase(string $name): string
    {
        $name = trim(str_replace(["\r", "\n"], ' ', $name));

        if (preg_match('/[^\x20-\x7e]/', $name) === 1) {
            return self::encode($name);
        }

        if (preg_match('/[()<>\[\]:;@\\\\,."]/', $name) === 1) {
            return '"'.addcslashes($name, '"\\').'"';
        }

        return $name;
    }

    /**
     * A parameter for a MIME header: plain where it can be, RFC 2231 encoded
     * where it holds anything else. Filenames are sent both ways, because
     * some clients still read only the plain one.
     */
    public static function parameter(string $name, string $value): string
    {
        $value = str_replace(["\r", "\n", "\0"], '', $value);

        if (preg_match('/^[\x20-\x7e]*$/', $value) === 1) {
            return $name.'="'.addcslashes($value, '"\\').'"';
        }

        return $name.'="'.self::encode($value).'";'."\r\n ".$name."*=UTF-8''".rawurlencode($value);
    }

    /**
     * Fold a header line at spaces so no line runs past 78 characters.
     */
    public static function fold(string $name, string $value): string
    {
        $line = $name.': ';
        $length = strlen($line);
        $output = $line;

        foreach (preg_split('/(?<=[ ,])/', $value) ?: [] as $word) {
            if ($word === '') {
                continue;
            }

            if ($length + strlen($word) > 78 && $length > strlen($name) + 2) {
                $output = rtrim($output, ' ')."\r\n ";
                $length = 1;
                $word = ltrim($word, ' ');
            }

            $output .= $word;
            $newline = strrpos($word, "\n");
            $length = $newline === false ? $length + strlen($word) : strlen($word) - $newline - 1;
        }

        return $output;
    }

    /**
     * @return list<string>
     */
    public static function splitOutsideQuotes(string $value, string $separator): array
    {
        $parts = [];
        $current = '';
        $quoted = false;
        $escaped = false;
        $depth = 0;

        for ($i = 0, $length = strlen($value); $i < $length; $i++) {
            $char = $value[$i];

            if ($escaped) {
                $current .= $char;
                $escaped = false;

                continue;
            }

            if ($char === '\\' && $quoted) {
                $current .= $char;
                $escaped = true;

                continue;
            }

            if ($char === '"') {
                $quoted = ! $quoted;
            } elseif (! $quoted && $char === '(') {
                $depth++;
            } elseif (! $quoted && $char === ')') {
                $depth = max(0, $depth - 1);
            } elseif (! $quoted && $depth === 0 && $char === $separator) {
                $parts[] = $current;
                $current = '';

                continue;
            }

            $current .= $char;
        }

        $parts[] = $current;

        return $parts;
    }

    private static function unquote(string $value): string
    {
        if (strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) {
            return (string) preg_replace('/\\\\(.)/s', '$1', substr($value, 1, -1));
        }

        return $value;
    }
}
