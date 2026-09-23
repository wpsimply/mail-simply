<?php

declare(strict_types=1);

namespace MailSimply\Imap;

use RuntimeException;

/**
 * Turns one IMAP response, literals included, into values.
 *
 * An atom or a string becomes a PHP string, NIL becomes null, and a
 * parenthesised list becomes a PHP list. Atoms may carry a bracketed section
 * with spaces inside it, as fetch items do (`BODY[HEADER.FIELDS (FROM)]`), and
 * a partial range after it (`BODY[1]<0>`): both are kept as part of the atom.
 *
 * Literals arrive inline, exactly as the server sent them: `{12}\r\n` followed
 * by twelve bytes. The byte count is what makes that unambiguous, so a literal
 * may hold anything at all, CRLF and parentheses included.
 */
final class Parser
{
    private int $position = 0;

    private readonly int $length;

    private function __construct(private readonly string $input)
    {
        $this->length = strlen($input);
    }

    /**
     * Parse a whole response into its top-level values.
     *
     * @return list<mixed>
     */
    public static function parse(string $response): array
    {
        $parser = new self(rtrim($response, "\r\n"));
        $values = [];

        while (true) {
            $parser->skipSpaces();

            if ($parser->position >= $parser->length) {
                return $values;
            }

            $values[] = $parser->value();
        }
    }

    /**
     * Turn a fetch item list, `(UID 4 FLAGS (\Seen) ...)`, into name => value.
     *
     * Names are upper-cased, and a partial range is dropped from them, so a
     * part fetched with `BODY.PEEK[1]<0.2048>` is found under `BODY[1]`.
     *
     * @param  list<mixed>  $items
     * @return array<string, mixed>
     */
    public static function pairs(array $items): array
    {
        $pairs = [];

        for ($i = 0; $i + 1 < count($items); $i += 2) {
            if (! is_string($items[$i])) {
                continue;
            }

            $name = strtoupper((string) preg_replace('/<\d+>$/', '', $items[$i]));
            $pairs[$name] = $items[$i + 1];
        }

        return $pairs;
    }

    private function value(): mixed
    {
        $char = $this->input[$this->position];

        return match ($char) {
            '(' => $this->list(),
            '"' => $this->quoted(),
            '{' => $this->literal(),
            default => $this->atom(),
        };
    }

    /**
     * @return list<mixed>
     */
    private function list(): array
    {
        $this->position++;
        $items = [];

        while (true) {
            $this->skipSpaces();

            if ($this->position >= $this->length) {
                // A list the server never closed: keep what arrived rather
                // than failing the whole response over it.
                return $items;
            }

            if ($this->input[$this->position] === ')') {
                $this->position++;

                return $items;
            }

            $items[] = $this->value();
        }
    }

    private function quoted(): string
    {
        $this->position++;
        $value = '';

        while ($this->position < $this->length) {
            $char = $this->input[$this->position++];

            if ($char === '\\' && $this->position < $this->length) {
                $value .= $this->input[$this->position++];

                continue;
            }

            if ($char === '"') {
                return $value;
            }

            $value .= $char;
        }

        return $value;
    }

    private function literal(): string
    {
        $end = strpos($this->input, '}', $this->position);

        if ($end === false) {
            throw new RuntimeException('Malformed literal in an IMAP response.');
        }

        $size = (int) rtrim(substr($this->input, $this->position + 1, $end - $this->position - 1), '+');
        $start = $end + 1;

        if (substr($this->input, $start, 2) === "\r\n") {
            $start += 2;
        } elseif (($this->input[$start] ?? '') === "\n") {
            $start++;
        }

        $this->position = $start + $size;

        return substr($this->input, $start, $size);
    }

    private function atom(): ?string
    {
        $start = $this->position;
        $depth = 0;

        while ($this->position < $this->length) {
            $char = $this->input[$this->position];

            if ($char === '[') {
                $depth++;
            } elseif ($char === ']') {
                $depth = max(0, $depth - 1);
            } elseif ($depth === 0 && ($char === ' ' || $char === '(' || $char === ')' || $char === "\r" || $char === "\n")) {
                break;
            }

            $this->position++;
        }

        if ($this->position === $start) {
            // A stray character no rule claims; step over it.
            $this->position++;

            return $this->input[$start];
        }

        $atom = substr($this->input, $start, $this->position - $start);

        return strtoupper($atom) === 'NIL' ? null : $atom;
    }

    private function skipSpaces(): void
    {
        while ($this->position < $this->length && in_array($this->input[$this->position], [' ', "\r", "\n"], true)) {
            $this->position++;
        }
    }
}
