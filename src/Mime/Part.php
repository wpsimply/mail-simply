<?php

declare(strict_types=1);

namespace MailSimply\Mime;

/**
 * One node of a message's structure, read from IMAP BODYSTRUCTURE.
 *
 * Sections are numbered the way IMAP fetches them: the children of a
 * multipart are 1, 2, ... below its own number, and the body of an attached
 * message/rfc822 is numbered below that part's. A message that is not
 * multipart has its one body at section "1".
 */
final class Part
{
    /**
     * @param  array<string, string>  $parameters
     * @param  array<string, string>  $dispositionParameters
     * @param  list<Part>  $parts
     */
    public function __construct(
        public readonly string $section,
        public readonly string $type,
        public readonly string $subtype,
        public readonly array $parameters = [],
        public readonly ?string $id = null,
        public readonly string $encoding = '7bit',
        public readonly int $size = 0,
        public readonly ?string $disposition = null,
        public readonly array $dispositionParameters = [],
        public readonly array $parts = [],
    ) {}

    /**
     * Parse a BODYSTRUCTURE value.
     */
    public static function fromStructure(mixed $structure, string $prefix = ''): self
    {
        if (! is_array($structure) || $structure === []) {
            return new self($prefix === '' ? '1' : $prefix, 'text', 'plain');
        }

        // Multipart: the child parts come first, each itself a list.
        if (is_array($structure[0])) {
            $children = [];
            $index = 0;

            while (isset($structure[$index]) && is_array($structure[$index])) {
                $section = ($prefix === '' ? '' : $prefix.'.').($index + 1);
                $children[] = self::fromStructure($structure[$index], $section);
                $index++;
            }

            $subtype = strtolower((string) ($structure[$index] ?? 'mixed'));
            $parameters = self::parameterList($structure[$index + 1] ?? null);
            [$disposition, $dispositionParameters] = self::disposition($structure[$index + 2] ?? null);

            return new self($prefix === '' ? '' : $prefix, 'multipart', $subtype, $parameters, null, '7bit', 0, $disposition, $dispositionParameters, $children);
        }

        $section = $prefix === '' ? '1' : $prefix;
        $type = strtolower((string) ($structure[0] ?? 'text'));
        $subtype = strtolower((string) ($structure[1] ?? 'plain'));
        $parameters = self::parameterList($structure[2] ?? null);
        $id = is_string($structure[3] ?? null) ? trim($structure[3], " <>\t") : null;
        $encoding = strtolower((string) ($structure[5] ?? '7bit'));
        $size = (int) ($structure[6] ?? 0);
        $extension = 7;
        $children = [];

        if ($type === 'text') {
            // Line count.
            $extension = 8;
        } elseif ($type === 'message' && in_array($subtype, ['rfc822', 'global'], true)) {
            // Envelope, the encapsulated message's structure, line count.
            if (isset($structure[8]) && is_array($structure[8])) {
                $inner = self::fromStructure($structure[8], $section);
                // A single-part body is numbered below the message part.
                $children = $inner->type === 'multipart' ? $inner->parts : [self::fromStructure($structure[8], $section.'.1')];
            }

            $extension = 10;
        }

        // Extension data: MD5, then disposition.
        [$disposition, $dispositionParameters] = self::disposition($structure[$extension + 1] ?? null);

        return new self($section, $type, $subtype, $parameters, $id !== '' ? $id : null, $encoding, $size, $disposition, $dispositionParameters, $children);
    }

    public function mimeType(): string
    {
        return $this->type.'/'.$this->subtype;
    }

    public function charset(): ?string
    {
        return $this->parameters['charset'] ?? null;
    }

    /**
     * The name the part was sent with, if any.
     */
    public function filename(): ?string
    {
        $name = $this->dispositionParameters['filename'] ?? $this->parameters['name'] ?? null;

        if ($name === null || trim($name) === '') {
            return null;
        }

        // Only the last path segment, and nothing a filesystem would read as
        // a control character.
        $name = basename(str_replace('\\', '/', $name));

        return (string) preg_replace('/[\x00-\x1f\x7f]/', '', $name);
    }

    public function isMultipart(): bool
    {
        return $this->type === 'multipart';
    }

    public function isAttachedMessage(): bool
    {
        return $this->type === 'message' && in_array($this->subtype, ['rfc822', 'global'], true);
    }

    /**
     * Whether the part is text meant to be read in the message body, rather
     * than a file that happens to be text.
     */
    public function isBodyText(): bool
    {
        return $this->type === 'text'
            && in_array($this->subtype, ['plain', 'html'], true)
            && $this->disposition !== 'attachment'
            && ! ($this->disposition === null && $this->filename() !== null && $this->subtype !== 'html');
    }

    /**
     * Find a part by its section number.
     */
    public function find(string $section): ?self
    {
        if ($this->section === $section) {
            return $this;
        }

        foreach ($this->parts as $part) {
            if ($section === $part->section || str_starts_with($section, $part->section.'.') || $part->section === '') {
                $found = $part->find($section);

                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * Every leaf part, depth first.
     *
     * @return list<Part>
     */
    public function leaves(): array
    {
        if ($this->parts === []) {
            return [$this];
        }

        $leaves = [];

        foreach ($this->parts as $part) {
            array_push($leaves, ...$part->leaves());
        }

        return $leaves;
    }

    /**
     * @return array<string, string>
     */
    private static function parameterList(mixed $list): array
    {
        if (! is_array($list)) {
            return [];
        }

        $raw = [];

        for ($i = 0; $i + 1 < count($list); $i += 2) {
            if (is_string($list[$i]) && is_string($list[$i + 1])) {
                $raw[strtolower($list[$i])] = $list[$i + 1];
            }
        }

        return Header::combine($raw);
    }

    /**
     * @return array{0: ?string, 1: array<string, string>}
     */
    private static function disposition(mixed $value): array
    {
        if (! is_array($value) || ! is_string($value[0] ?? null)) {
            return [null, []];
        }

        return [strtolower($value[0]), self::parameterList($value[1] ?? null)];
    }
}
