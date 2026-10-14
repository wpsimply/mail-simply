<?php

declare(strict_types=1);

namespace MailSimply;

/**
 * What went wrong for one mailbox lately, kept for its owner to look back on:
 * a message that would not send, a copy that could not be saved.
 *
 * Only what the user was already told is kept -- the error as they saw it,
 * and which message it was about. Server names, the SMTP conversation and
 * everything else meant for whoever runs the mail host goes to the
 * application log ({@see Log}) and never here.
 *
 * An entry holds the English source string and its replacements rather than
 * the text itself, so it is read back in whatever language the user has
 * switched to since. It lives beside the mailbox's settings, named after the
 * same hash of the address, and only the newest entries are kept.
 */
final class Problems
{
    public const int MAX = 50;

    public function __construct(private readonly string $directory, private readonly string $address) {}

    /**
     * Keep the error the user was shown, with what it was about.
     *
     * @param  array{subject?: string, recipients?: list<string>}  $about
     */
    public function record(UserError $error, array $about = []): void
    {
        $entries = $this->read();

        array_unshift($entries, [
            'time' => time(),
            'message' => $error->getMessage(),
            'replace' => $error->replace,
            'subject' => mb_substr((string) ($about['subject'] ?? ''), 0, 200),
            'recipients' => array_slice(array_values($about['recipients'] ?? []), 0, 10),
        ]);

        $this->write(array_slice($entries, 0, self::MAX));
    }

    /**
     * The problems kept, newest first, in the given language.
     *
     * @return list<array{time: string, message: string, subject: string, recipients: list<string>}>
     */
    public function all(Lang $lang): array
    {
        return array_map(static fn (array $entry): array => [
            'time' => gmdate('Y-m-d\TH:i:s\Z', $entry['time']),
            'message' => $lang->get($entry['message'], $entry['replace']),
            'subject' => $entry['subject'],
            'recipients' => $entry['recipients'],
        ], $this->read());
    }

    public function clear(): void
    {
        @unlink($this->file());
    }

    private function file(): string
    {
        return $this->directory.'/'.hash('sha256', strtolower($this->address)).'.problems.json';
    }

    /**
     * @return list<array{time: int, message: string, replace: array<string, string|int>, subject: string, recipients: list<string>}>
     */
    private function read(): array
    {
        $stored = json_decode((string) @file_get_contents($this->file()), true);

        if (! is_array($stored)) {
            return [];
        }

        $entries = [];

        foreach ($stored as $entry) {
            if (! is_array($entry) || ! is_int($entry['time'] ?? null) || ! is_string($entry['message'] ?? null)) {
                continue;
            }

            $entries[] = [
                'time' => $entry['time'],
                'message' => $entry['message'],
                'replace' => array_filter(is_array($entry['replace'] ?? null) ? $entry['replace'] : [], static fn (mixed $value): bool => is_string($value) || is_int($value)),
                'subject' => is_string($entry['subject'] ?? null) ? $entry['subject'] : '',
                'recipients' => array_values(array_filter(is_array($entry['recipients'] ?? null) ? $entry['recipients'] : [], is_string(...))),
            ];
        }

        return $entries;
    }

    /**
     * Written whole and renamed into place, like the settings beside it.
     * Failing to keep a problem must never become a problem of its own, so
     * this only logs.
     *
     * @param  list<array<string, mixed>>  $entries
     */
    private function write(array $entries): void
    {
        if (! is_dir($this->directory) && ! @mkdir($this->directory, 0700, true) && ! is_dir($this->directory)) {
            Log::error('A problem could not be kept for the mailbox.', ['address' => $this->address, 'directory' => $this->directory]);

            return;
        }

        $file = $this->file();
        $temporary = $file.'.'.bin2hex(random_bytes(4)).'.tmp';

        if (@file_put_contents($temporary, json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)) === false) {
            Log::error('A problem could not be kept for the mailbox.', ['address' => $this->address, 'file' => $file]);

            return;
        }

        @chmod($temporary, 0600);

        if (! @rename($temporary, $file)) {
            @unlink($temporary);
        }
    }
}
