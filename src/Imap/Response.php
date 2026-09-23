<?php

declare(strict_types=1);

namespace MailSimply\Imap;

/**
 * What a command brought back: its untagged responses, parsed, and the text
 * and response code of its tagged completion.
 */
final readonly class Response
{
    /**
     * @param  list<list<mixed>>  $untagged  each without its leading "*"
     */
    public function __construct(
        public array $untagged,
        public ?string $code,
        public string $text,
    ) {}

    /**
     * The untagged responses of one kind, e.g. "LIST" or "SEARCH", each
     * without the keyword itself.
     *
     * @return list<list<mixed>>
     */
    public function named(string $name): array
    {
        $matches = [];

        foreach ($this->untagged as $response) {
            if (is_string($response[0] ?? null) && strcasecmp($response[0], $name) === 0) {
                $matches[] = array_slice($response, 1);
            }
        }

        return $matches;
    }

    /**
     * The FETCH responses, as fetch item => value, in the order they came.
     *
     * @return list<array<string, mixed>>
     */
    public function fetched(): array
    {
        $messages = [];

        foreach ($this->untagged as $response) {
            if (is_string($response[1] ?? null) && strcasecmp($response[1], 'FETCH') === 0 && is_array($response[2] ?? null)) {
                $messages[] = ['SEQ' => (int) $response[0], ...Parser::pairs($response[2])];
            }
        }

        return $messages;
    }
}
