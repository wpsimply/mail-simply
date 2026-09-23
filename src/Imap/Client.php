<?php

declare(strict_types=1);

namespace MailSimply\Imap;

use MailSimply\Socket;
use RuntimeException;

/**
 * An IMAP4rev1 client: just the commands a webmail needs, spoken the way
 * Dovecot answers them, with the common extensions used where the server
 * offers them (LITERAL+, SASL-IR, SPECIAL-USE, LIST-STATUS, SORT, MOVE,
 * UIDPLUS, QUOTA) and plainer fallbacks where it does not.
 *
 * Everything addresses messages by UID. Sequence numbers shift under a client
 * as soon as another one expunges, and every request here is a connection of
 * its own, so a number read in one request means nothing in the next.
 */
final class Client
{
    private int $tag = 0;

    /** @var list<string> */
    private array $capabilities = [];

    private ?string $selected = null;

    private function __construct(private readonly Socket $socket)
    {
        $greeting = $this->socket->readLine();

        if (! str_starts_with($greeting, '* OK') && ! str_starts_with($greeting, '* PREAUTH')) {
            throw new RuntimeException('The IMAP server refused the connection: '.trim($greeting));
        }

        $this->readCapabilities($greeting);
    }

    /**
     * Connect, upgrading to TLS first when the configuration asks for it.
     *
     * @param  array{host: string, port: int, encryption: string, verify: bool, ca_file: ?string, timeout: int}  $options
     */
    public static function connect(array $options): self
    {
        $client = new self(new Socket($options));

        if ($options['encryption'] === 'starttls') {
            if ($client->capabilities === []) {
                $client->capability();
            }

            if (! $client->has('STARTTLS')) {
                throw new RuntimeException('The IMAP server does not offer STARTTLS.');
            }

            $client->command('STARTTLS');
            $client->socket->startTls();
            // What was advertised before TLS may not be trusted after it.
            $client->capability();
        }

        return $client;
    }

    /**
     * Sign in. With an authorisation identity the given user and password are
     * a master user's, authenticating on that mailbox's behalf over SASL PLAIN.
     */
    public function authenticate(string $user, string $password, ?string $authorize = null): void
    {
        if ($this->capabilities === []) {
            $this->capability();
        }

        try {
            if ($authorize === null && ! $this->has('AUTH=PLAIN')) {
                $this->command('LOGIN', [$this->astring($user), $this->astring($password)]);
            } else {
                $this->plain(base64_encode(($authorize ?? '')."\0".$user."\0".$password));
            }
        } catch (CommandFailed $e) {
            throw new AuthenticationFailed($e->text, previous: $e);
        }

        // The server lists what it offers signed in, which is more than it
        // offered before; ask when the completion did not say.
        $this->capability();
    }

    /**
     * @return list<string>
     */
    public function capabilities(): array
    {
        return $this->capabilities;
    }

    public function has(string $capability): bool
    {
        return in_array(strtoupper($capability), $this->capabilities, true);
    }

    public function capability(): void
    {
        $response = $this->command('CAPABILITY');

        foreach ($response->named('CAPABILITY') as $list) {
            $this->capabilities = array_map(static fn (mixed $item): string => strtoupper((string) $item), $list);
        }
    }

    /**
     * Every folder, with its hierarchy delimiter, attributes and -- when the
     * server can return them in the same round trip -- its message counts.
     *
     * @return list<array{name: string, delimiter: ?string, attributes: list<string>, status: ?array<string, int>}>
     */
    public function folders(): array
    {
        $withStatus = $this->has('LIST-STATUS');
        $return = [];

        if ($this->has('SPECIAL-USE')) {
            $return[] = 'SPECIAL-USE';
        }

        $return[] = 'SUBSCRIBED';

        if ($withStatus) {
            $return[] = 'STATUS (MESSAGES UNSEEN UIDNEXT)';
        }

        $extended = $this->has('LIST-EXTENDED');
        $response = $extended
            ? $this->command('LIST', ['""', '"*"', 'RETURN ('.implode(' ', $return).')'])
            : $this->command('LIST', ['""', '"*"']);

        $statuses = [];

        foreach ($response->named('STATUS') as $status) {
            $statuses[(string) $status[0]] = self::statusPairs($status[1] ?? []);
        }

        $folders = [];

        foreach ($response->named('LIST') as [$attributes, $delimiter, $name]) {
            $name = (string) $name;
            $folders[] = [
                'name' => $name,
                'delimiter' => $delimiter,
                'attributes' => array_map(static fn (mixed $flag): string => (string) $flag, is_array($attributes) ? $attributes : []),
                'status' => $statuses[$name] ?? null,
            ];
        }

        return $folders;
    }

    /**
     * @return array<string, int>
     */
    public function status(string $folder): array
    {
        $response = $this->command('STATUS', [$this->mailbox($folder), '(MESSAGES UNSEEN UIDNEXT UIDVALIDITY)']);

        foreach ($response->named('STATUS') as $status) {
            return self::statusPairs($status[1] ?? []);
        }

        return [];
    }

    /**
     * Open a folder, read-only unless it is going to be changed. Opening the
     * folder already open is free.
     *
     * @return array<string, int>
     */
    public function select(string $folder, bool $writable = false): array
    {
        $key = ($writable ? 'rw:' : 'ro:').$folder;

        if ($this->selected === $key) {
            return [];
        }

        $response = $this->command($writable ? 'SELECT' : 'EXAMINE', [$this->mailbox($folder)]);
        $this->selected = $key;
        $info = [];

        foreach ($response->untagged as $line) {
            if (isset($line[1]) && is_string($line[1]) && strcasecmp($line[1], 'EXISTS') === 0) {
                $info['exists'] = (int) $line[0];
            }
        }

        return $info;
    }

    /**
     * UIDs matching the search, most recent first where the server can sort.
     *
     * @param  list<string|Literal>  $criteria
     * @return list<int>
     */
    public function sortedUids(array $criteria, bool $utf8): array
    {
        $criteria = $criteria === [] ? ['ALL'] : $criteria;

        if ($this->has('SORT')) {
            $response = $this->command('UID SORT', ['(REVERSE DATE)', $utf8 ? 'UTF-8' : 'US-ASCII', ...$criteria]);

            return self::numbers($response->named('SORT'));
        }

        $response = $this->command('UID SEARCH', [...($utf8 ? ['CHARSET', 'UTF-8'] : []), ...$criteria]);
        $uids = self::numbers($response->named('SEARCH'));
        rsort($uids);

        return $uids;
    }

    /**
     * UIDs matching the search, in no particular order.
     *
     * @param  list<string|Literal>  $criteria
     * @return list<int>
     */
    public function search(array $criteria): array
    {
        return self::numbers($this->command('UID SEARCH', $criteria)->named('SEARCH'));
    }

    /**
     * Fetch items for the given UIDs, keyed by UID.
     *
     * @param  list<int>  $uids
     * @return array<int, array<string, mixed>>
     */
    public function fetch(array $uids, string $items): array
    {
        if ($uids === []) {
            return [];
        }

        $messages = [];

        foreach ($this->command('UID FETCH', [self::set($uids), $items])->fetched() as $message) {
            if (isset($message['UID'])) {
                $messages[(int) $message['UID']] = $message;
            }
        }

        return $messages;
    }

    /**
     * Fetch one body section and hand it to the sink as it arrives, so an
     * attachment of any size passes through without being held in memory.
     *
     * Returns false when the server has no such message or section.
     *
     * @param  callable(string): void  $sink
     */
    public function stream(int $uid, string $section, callable $sink): bool
    {
        $tag = $this->send('UID FETCH', [(string) $uid, '(BODY.PEEK['.$section.'])']);
        $found = false;

        while (true) {
            $line = $this->socket->readLine();

            if (str_starts_with($line, $tag.' ')) {
                $this->complete($line, 'UID FETCH');

                return $found;
            }

            // Only the section's own literal is worth anything; any other one
            // (an unsolicited FLAGS never has one) is read and dropped.
            while (preg_match('/\{(\d+)\+?\}\r?\n$/', $line, $match) === 1) {
                $size = (int) $match[1];

                if (! $found && stripos($line, 'BODY['.$section.']') !== false) {
                    $found = true;
                    $this->socket->stream($size, $sink);
                } else {
                    $this->socket->read($size);
                }

                $line = $this->socket->readLine();
            }
        }
    }

    /**
     * @param  list<int>  $uids
     * @param  list<string>  $flags
     */
    public function store(array $uids, string $mode, array $flags): void
    {
        if ($uids === [] || $flags === []) {
            return;
        }

        $this->command('UID STORE', [self::set($uids), $mode.'FLAGS.SILENT', '('.implode(' ', $flags).')']);
    }

    /**
     * Move messages to another folder, the atomic way where the server can.
     *
     * @param  list<int>  $uids
     */
    public function move(array $uids, string $target): void
    {
        if ($uids === []) {
            return;
        }

        if ($this->has('MOVE')) {
            $this->command('UID MOVE', [self::set($uids), $this->mailbox($target)]);

            return;
        }

        $this->command('UID COPY', [self::set($uids), $this->mailbox($target)]);
        $this->expunge($uids);
    }

    /**
     * @param  list<int>  $uids
     */
    public function copy(array $uids, string $target): void
    {
        if ($uids !== []) {
            $this->command('UID COPY', [self::set($uids), $this->mailbox($target)]);
        }
    }

    /**
     * Delete messages for good. With UIDPLUS only the given ones go; without
     * it, everything flagged \Deleted in the folder does.
     *
     * @param  list<int>  $uids
     */
    public function expunge(array $uids): void
    {
        if ($uids === []) {
            return;
        }

        $this->store($uids, '+', ['\\Deleted']);

        if ($this->has('UIDPLUS')) {
            $this->command('UID EXPUNGE', [self::set($uids)]);
        } else {
            $this->command('EXPUNGE');
        }
    }

    /**
     * Add a message to a folder, returning its UID where the server says.
     *
     * @param  list<string>  $flags
     */
    public function append(string $folder, string $message, array $flags = []): ?int
    {
        $response = $this->command('APPEND', [
            $this->mailbox($folder),
            '('.implode(' ', $flags).')',
            new Literal($message),
        ]);

        // [APPENDUID <uidvalidity> <uid>]
        if ($response->code !== null && preg_match('/^APPENDUID \d+ (\d+)$/i', $response->code, $match) === 1) {
            return (int) $match[1];
        }

        return null;
    }

    public function create(string $folder): void
    {
        $this->command('CREATE', [$this->mailbox($folder)]);
        $this->subscribe($folder, true);
    }

    public function delete(string $folder): void
    {
        if ($this->selected !== null && substr($this->selected, 3) === $folder) {
            $this->command('UNSELECT');
            $this->selected = null;
        }

        $this->command('DELETE', [$this->mailbox($folder)]);
    }

    public function rename(string $from, string $to): void
    {
        $this->command('RENAME', [$this->mailbox($from), $this->mailbox($to)]);
        $this->subscribe($to, true);
    }

    public function subscribe(string $folder, bool $subscribed): void
    {
        try {
            $this->command($subscribed ? 'SUBSCRIBE' : 'UNSUBSCRIBE', [$this->mailbox($folder)]);
        } catch (CommandFailed) {
            // Subscriptions are a courtesy to other clients; never fatal.
        }
    }

    /**
     * Storage used and allowed, in bytes, or null where the server keeps no
     * quota for the mailbox.
     *
     * @return array{used: int, limit: int}|null
     */
    public function quota(): ?array
    {
        if (! $this->has('QUOTA')) {
            return null;
        }

        try {
            $response = $this->command('GETQUOTAROOT', ['INBOX']);
        } catch (CommandFailed) {
            return null;
        }

        foreach ($response->named('QUOTA') as $quota) {
            $resources = $quota[1] ?? [];

            for ($i = 0; $i + 2 < count($resources); $i += 3) {
                if (strcasecmp((string) ($resources[$i] ?? ''), 'STORAGE') === 0) {
                    // QUOTA speaks in KiB.
                    return ['used' => (int) $resources[$i + 1] * 1024, 'limit' => (int) $resources[$i + 2] * 1024];
                }
            }
        }

        return null;
    }

    public function logout(): void
    {
        try {
            $this->command('LOGOUT');
        } catch (\Throwable) {
            // The session ends either way.
        } finally {
            $this->socket->close();
        }
    }

    /**
     * A folder name as a command argument.
     */
    public function mailbox(string $name): string|Literal
    {
        return $this->astring($name);
    }

    /**
     * A string argument: quoted where a quoted string can carry it, a literal
     * where it cannot.
     */
    public function astring(string $value): string|Literal
    {
        if (preg_match('/[\x00-\x1f\x7f-\xff]/', $value) === 1 || strlen($value) > 1024) {
            return new Literal($value);
        }

        return '"'.addcslashes($value, '"\\').'"';
    }

    /**
     * Run a command and wait for its completion.
     *
     * @param  list<string|Literal>  $arguments
     */
    public function command(string $command, array $arguments = []): Response
    {
        $tag = $this->send($command, $arguments);
        $untagged = [];

        while (true) {
            $response = $this->readResponse();

            if (str_starts_with($response, '* ')) {
                $untagged[] = Parser::parse(substr($response, 2));

                continue;
            }

            if (str_starts_with($response, $tag.' ')) {
                [$code, $text] = $this->complete($response, $command);

                return new Response($untagged, $code, $text);
            }

            // A continuation nobody asked for; nothing to answer it with.
        }
    }

    /**
     * Write a command, feeding its literals in as the server allows, and
     * return the tag its completion will carry.
     *
     * @param  list<string|Literal>  $arguments
     */
    private function send(string $command, array $arguments): string
    {
        $tag = 'm'.(++$this->tag);
        $line = $tag.' '.$command;
        $plus = $this->has('LITERAL+');

        foreach ($arguments as $argument) {
            if (! $argument instanceof Literal) {
                $line .= ' '.$argument;

                continue;
            }

            $line .= ' {'.strlen($argument->value).($plus ? '+' : '')."}\r\n";
            $this->socket->write($line);

            if (! $plus) {
                $this->awaitContinuation($command);
            }

            $line = $argument->value;
        }

        $this->socket->write($line."\r\n");

        return $tag;
    }

    /**
     * AUTHENTICATE PLAIN, with the initial response inline where the server
     * takes it that way.
     */
    private function plain(string $credentials): void
    {
        if ($this->has('SASL-IR')) {
            $this->command('AUTHENTICATE', ['PLAIN', $credentials]);

            return;
        }

        $tag = 'm'.(++$this->tag);
        $this->socket->write($tag." AUTHENTICATE PLAIN\r\n");
        $this->awaitContinuation('AUTHENTICATE');
        $this->socket->write($credentials."\r\n");

        while (true) {
            $response = $this->readResponse();

            if (str_starts_with($response, $tag.' ')) {
                $this->complete($response, 'AUTHENTICATE');

                return;
            }
        }
    }

    private function awaitContinuation(string $command): void
    {
        while (true) {
            $response = $this->readResponse();

            if (str_starts_with($response, '+')) {
                return;
            }

            if (preg_match('/^m\d+ (NO|BAD) (.*)$/s', $response, $match) === 1) {
                throw new CommandFailed($match[1], null, trim($match[2]), $command);
            }
        }
    }

    /**
     * Check a tagged completion, returning its response code and text.
     *
     * @return array{0: ?string, 1: string}
     */
    private function complete(string $line, string $command): array
    {
        if (preg_match('/^\S+ (OK|NO|BAD)(?: \[([^\]]*)\])? ?(.*)$/s', rtrim($line, "\r\n"), $match) !== 1) {
            throw new RuntimeException('Unexpected IMAP response: '.trim($line));
        }

        $code = $match[2] !== '' ? $match[2] : null;
        $text = trim($match[3]);

        if ($match[1] !== 'OK') {
            throw new CommandFailed($match[1], $code, $text, strtok($command, ' ') ?: $command);
        }

        if ($code !== null && str_starts_with(strtoupper($code), 'CAPABILITY ')) {
            $this->readCapabilities('* OK ['.$code.']');
        }

        return [$code, $text];
    }

    /**
     * Read one whole response: its first line, and every literal and line
     * after it that the byte counts say belong to it.
     */
    private function readResponse(): string
    {
        $response = $this->socket->readLine();

        while (preg_match('/\{(\d+)\}\r?\n$/', $response, $match) === 1) {
            $response .= $this->socket->read((int) $match[1]);
            $response .= $this->socket->readLine();
        }

        return $response;
    }

    private function readCapabilities(string $line): void
    {
        if (preg_match('/\[CAPABILITY ([^\]]+)\]/i', $line, $match) === 1) {
            $this->capabilities = array_map(strtoupper(...), preg_split('/\s+/', trim($match[1])) ?: []);
        }
    }

    /**
     * @param  list<mixed>  $items
     * @return array<string, int>
     */
    private static function statusPairs(array $items): array
    {
        $status = [];

        for ($i = 0; $i + 1 < count($items); $i += 2) {
            $status[strtolower((string) $items[$i])] = (int) $items[$i + 1];
        }

        return $status;
    }

    /**
     * @param  list<list<mixed>>  $responses
     * @return list<int>
     */
    private static function numbers(array $responses): array
    {
        $numbers = [];

        foreach ($responses as $response) {
            foreach ($response as $item) {
                if (is_string($item) && ctype_digit($item)) {
                    $numbers[] = (int) $item;
                }
            }
        }

        return $numbers;
    }

    /**
     * A UID set, with runs collapsed: 1,2,3,7 becomes 1:3,7.
     *
     * @param  list<int>  $uids
     */
    public static function set(array $uids): string
    {
        $uids = array_values(array_unique(array_map(intval(...), $uids)));
        sort($uids);
        $ranges = [];
        $start = $end = null;

        foreach ($uids as $uid) {
            if ($end !== null && $uid === $end + 1) {
                $end = $uid;

                continue;
            }

            if ($start !== null) {
                $ranges[] = $start === $end ? (string) $start : $start.':'.$end;
            }

            $start = $end = $uid;
        }

        if ($start !== null) {
            $ranges[] = $start === $end ? (string) $start : $start.':'.$end;
        }

        return implode(',', $ranges);
    }
}
