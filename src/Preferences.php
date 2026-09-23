<?php

declare(strict_types=1);

namespace MailSimply;

/**
 * One mailbox's settings and the addresses it has written to, kept in a JSON
 * file of its own under storage/users.
 *
 * There is no database. The file is named after a hash of the address, so a
 * directory listing gives no addresses away, and it is written whole to a
 * temporary file and renamed into place, so a reader never sees half of it.
 */
final class Preferences
{
    public const int MAX_CONTACTS = 1000;

    public const int MAX_SIGNATURE = 20000;

    /**
     * @var array{name: ?string, signature: string, html: bool, language: ?string, trusted: list<string>, contacts: array<string, array{name: string, count: int, last: int}>}
     */
    private array $values;

    public function __construct(private readonly string $directory, private readonly string $address)
    {
        $this->values = $this->read();
    }

    /**
     * The name mail from this mailbox is sent under; null until one is set.
     */
    public function name(): ?string
    {
        return $this->values['name'];
    }

    public function signature(): string
    {
        return $this->values['signature'];
    }

    public function html(): bool
    {
        return $this->values['html'];
    }

    public function language(): ?string
    {
        return $this->values['language'];
    }

    /**
     * Whether remote content loads for mail from this sender without asking:
     * the address, or its whole domain, has been trusted.
     */
    public function trusts(string $sender): bool
    {
        $sender = strtolower(trim($sender));
        $at = strrpos($sender, '@');
        $domain = $at === false ? null : '@'.substr($sender, $at + 1);

        return in_array($sender, $this->values['trusted'], true) || ($domain !== null && in_array($domain, $this->values['trusted'], true));
    }

    /**
     * @return list<string>
     */
    public function trusted(): array
    {
        return $this->values['trusted'];
    }

    /**
     * @param  array{name?: ?string, signature?: string, html?: bool, language?: ?string, trusted?: list<string>}  $changes
     */
    public function update(array $changes): void
    {
        if (array_key_exists('name', $changes)) {
            $name = trim(str_replace(["\r", "\n"], ' ', (string) $changes['name']));
            $this->values['name'] = $name === '' ? null : mb_substr($name, 0, 120);
        }

        if (array_key_exists('signature', $changes)) {
            $this->values['signature'] = mb_substr((string) $changes['signature'], 0, self::MAX_SIGNATURE);
        }

        if (array_key_exists('html', $changes)) {
            $this->values['html'] = (bool) $changes['html'];
        }

        if (array_key_exists('language', $changes)) {
            $language = $changes['language'];
            $this->values['language'] = is_string($language) && Lang::isAvailable($language) ? $language : null;
        }

        if (array_key_exists('trusted', $changes)) {
            $this->values['trusted'] = self::normalizeTrusted($changes['trusted']);
        }

        $this->write();
    }

    /**
     * Trust a sender, or with $domain the whole of the sender's domain.
     */
    public function trust(string $sender, bool $domain): void
    {
        $sender = strtolower(trim($sender));
        $at = strrpos($sender, '@');

        if ($at === false) {
            return;
        }

        $entry = $domain ? '@'.substr($sender, $at + 1) : $sender;

        if (! in_array($entry, $this->values['trusted'], true)) {
            $this->values['trusted'][] = $entry;
            $this->values['trusted'] = self::normalizeTrusted($this->values['trusted']);
            $this->write();
        }
    }

    /**
     * Remember the addresses a message went to, for completion later.
     *
     * @param  list<array{name: string, email: string}>  $addresses
     */
    public function remember(array $addresses): void
    {
        if ($addresses === []) {
            return;
        }

        $now = time();

        foreach ($addresses as $address) {
            $email = strtolower($address['email']);

            if ($email === strtolower($this->address)) {
                continue;
            }

            $known = $this->values['contacts'][$email] ?? ['name' => '', 'count' => 0, 'last' => 0];
            $this->values['contacts'][$email] = [
                'name' => $address['name'] !== '' ? mb_substr($address['name'], 0, 120) : $known['name'],
                'count' => $known['count'] + 1,
                'last' => $now,
            ];
        }

        // The least used, least recent go first once the list is full.
        if (count($this->values['contacts']) > self::MAX_CONTACTS) {
            uasort($this->values['contacts'], static fn (array $a, array $b): int => [$b['count'], $b['last']] <=> [$a['count'], $a['last']]);
            $this->values['contacts'] = array_slice($this->values['contacts'], 0, self::MAX_CONTACTS, true);
        }

        $this->write();
    }

    /**
     * Remembered addresses matching what has been typed so far, most used
     * first.
     *
     * @return list<array{name: string, email: string}>
     */
    public function contacts(string $query, int $limit = 10): array
    {
        $query = mb_strtolower(trim($query));
        $matches = [];

        foreach ($this->values['contacts'] as $email => $contact) {
            if ($query === '' || str_contains((string) $email, $query) || str_contains(mb_strtolower($contact['name']), $query)) {
                $matches[] = ['name' => $contact['name'], 'email' => (string) $email, 'rank' => [$contact['count'], $contact['last']]];
            }
        }

        usort($matches, static fn (array $a, array $b): int => $b['rank'] <=> $a['rank']);

        return array_map(
            static fn (array $match): array => ['name' => $match['name'], 'email' => $match['email']],
            array_slice($matches, 0, $limit),
        );
    }

    public function forget(string $email): void
    {
        unset($this->values['contacts'][strtolower($email)]);
        $this->write();
    }

    private function file(): string
    {
        return $this->directory.'/'.hash('sha256', strtolower($this->address)).'.json';
    }

    /**
     * @return array{name: ?string, signature: string, html: bool, language: ?string, trusted: list<string>, contacts: array<string, array{name: string, count: int, last: int}>}
     */
    private function read(): array
    {
        $defaults = ['name' => null, 'signature' => '', 'html' => true, 'language' => null, 'trusted' => [], 'contacts' => []];
        $file = $this->file();

        if (! is_file($file)) {
            return $defaults;
        }

        $stored = json_decode((string) @file_get_contents($file), true);

        if (! is_array($stored)) {
            return $defaults;
        }

        return [
            'name' => is_string($stored['name'] ?? null) ? $stored['name'] : null,
            'signature' => is_string($stored['signature'] ?? null) ? $stored['signature'] : '',
            'html' => is_bool($stored['html'] ?? null) ? $stored['html'] : true,
            'language' => is_string($stored['language'] ?? null) ? $stored['language'] : null,
            'trusted' => self::normalizeTrusted($stored['trusted'] ?? []),
            'contacts' => is_array($stored['contacts'] ?? null) ? $stored['contacts'] : [],
        ];
    }

    private function write(): void
    {
        if (! is_dir($this->directory) && ! @mkdir($this->directory, 0700, true) && ! is_dir($this->directory)) {
            throw new UserError('Your settings could not be saved.', 500);
        }

        $file = $this->file();
        $temporary = $file.'.'.bin2hex(random_bytes(4)).'.tmp';

        if (@file_put_contents($temporary, json_encode($this->values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false) {
            throw new UserError('Your settings could not be saved.', 500);
        }

        @chmod($temporary, 0600);

        if (! @rename($temporary, $file)) {
            @unlink($temporary);

            throw new UserError('Your settings could not be saved.', 500);
        }
    }

    /**
     * @return list<string>
     */
    private static function normalizeTrusted(mixed $list): array
    {
        if (! is_array($list)) {
            return [];
        }

        $trusted = [];

        foreach ($list as $entry) {
            $entry = is_string($entry) ? strtolower(trim($entry)) : '';

            if ($entry !== '' && strlen($entry) <= 254 && str_contains($entry, '@') && ! str_contains($entry, ' ')) {
                $trusted[$entry] = true;
            }
        }

        return array_slice(array_keys($trusted), 0, 500);
    }
}
