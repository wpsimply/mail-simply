<?php

declare(strict_types=1);

namespace MailSimply;

use DateTimeImmutable;
use MailSimply\Html\Sanitizer;
use MailSimply\Html\Text;
use MailSimply\Imap\AuthenticationFailed;
use MailSimply\Imap\Client;
use MailSimply\Imap\CommandFailed;
use MailSimply\Imap\Literal;
use MailSimply\Mime\Address;
use MailSimply\Mime\Charset;
use MailSimply\Mime\Decoder;
use MailSimply\Mime\Header;
use MailSimply\Mime\Layout;
use MailSimply\Mime\Part;
use RuntimeException;
use Throwable;

/**
 * The signed-in mailbox, as the interface sees it: folders, message lists,
 * messages, and what can be done to them.
 *
 * Folders are identified by their name on the server, exactly as it spells
 * it (modified UTF-7 included), and shown by their decoded name. Messages
 * are identified by UID within their folder.
 */
final class Mailbox
{
    /**
     * The special folders a webmail has to know by purpose, and the names
     * they go by on servers that do not mark them (RFC 6154).
     */
    public const array ROLES = [
        'drafts' => ['attribute' => '\\Drafts', 'names' => ['drafts', 'draft', 'piszkozatok']],
        'sent' => ['attribute' => '\\Sent', 'names' => ['sent', 'sent items', 'sent messages', 'sent mail', 'elküldött', 'elküldött elemek']],
        'junk' => ['attribute' => '\\Junk', 'names' => ['junk', 'spam', 'junk e-mail', 'junk email', 'levélszemét']],
        'trash' => ['attribute' => '\\Trash', 'names' => ['trash', 'deleted items', 'deleted messages', 'bin', 'kuka', 'törölt elemek']],
        'archive' => ['attribute' => '\\Archive', 'names' => ['archive', 'archives', 'archívum']],
    ];

    /**
     * The most of one text part fetched for display: more than any honest
     * message body, less than a mailbox bomb.
     */
    private const int BODY_LIMIT = 5242880;

    /** @var list<array<string, mixed>>|null */
    private ?array $folders = null;

    public function __construct(private readonly Client $imap, public readonly string $address) {}

    /**
     * Connect and sign in as the session's mailbox.
     *
     * @param  array{address: string, name: string, master: bool, password: ?string}  $grant
     */
    public static function open(Config $config, array $grant): self
    {
        $imap = Client::connect(self::serverOptions($config, 'imap'));

        try {
            if ($grant['master']) {
                $imap->authenticate($config->string('master.user'), $config->string('master.password'), $grant['address']);
            } else {
                $imap->authenticate($grant['address'], (string) $grant['password']);
            }
        } catch (AuthenticationFailed $e) {
            $imap->logout();

            throw new UserError('The mail server did not accept this sign-in. Sign in again.', 401);
        }

        return new self($imap, $grant['address']);
    }

    /**
     * @return array{host: string, port: int, encryption: string, verify: bool, ca_file: ?string, timeout: int}
     */
    public static function serverOptions(Config $config, string $server): array
    {
        $encryption = strtolower($config->string($server.'.encryption'));

        return [
            'host' => $config->string($server.'.host'),
            'port' => $config->int($server.'.port'),
            'encryption' => in_array($encryption, ['ssl', 'tls', 'starttls', 'none'], true) ? ($encryption === 'tls' ? 'ssl' : $encryption) : 'starttls',
            'verify' => (bool) $config->get($server.'.verify', true),
            'ca_file' => $config->string($server.'.ca_file') ?: null,
            'timeout' => max(1, $config->int($server.'.timeout')),
        ];
    }

    public function imap(): Client
    {
        return $this->imap;
    }

    public function close(): void
    {
        $this->imap->logout();
    }

    /**
     * Every folder, special ones first in a fixed order, the rest by name,
     * each followed by its subfolders.
     *
     * @return list<array{id: string, name: string, path: string, depth: int, parent: ?string, delimiter: ?string, role: ?string, selectable: bool, total: ?int, unread: ?int, uidnext: ?int}>
     */
    public function folders(): array
    {
        if ($this->folders !== null) {
            return $this->folders;
        }

        $raw = $this->imap->folders();
        $names = array_column($raw, 'name');
        $roles = $this->assignRoles($raw);
        $folders = [];

        foreach ($raw as $folder) {
            $name = $folder['name'];
            $delimiter = is_string($folder['delimiter']) && $folder['delimiter'] !== '' ? $folder['delimiter'] : null;
            $segments = $delimiter === null ? [$name] : explode($delimiter, $name);
            $parent = count($segments) > 1 ? implode((string) $delimiter, array_slice($segments, 0, -1)) : null;
            $attributes = array_map(strtolower(...), $folder['attributes']);
            $status = $folder['status'];

            $folders[] = [
                'id' => $name,
                'name' => self::decodeName((string) end($segments)),
                'path' => self::decodeName($name),
                'depth' => 0,
                'parent' => $parent !== null && in_array($parent, $names, true) ? $parent : null,
                'delimiter' => $delimiter,
                'role' => strcasecmp($name, 'INBOX') === 0 ? 'inbox' : ($roles[$name] ?? null),
                'selectable' => ! in_array('\\noselect', $attributes, true) && ! in_array('\\nonexistent', $attributes, true),
                'total' => $status['messages'] ?? null,
                'unread' => $status['unseen'] ?? null,
                'uidnext' => $status['uidnext'] ?? null,
            ];
        }

        return $this->folders = self::order($folders);
    }

    /**
     * Unread and total counts for every folder that lacks them, for servers
     * without LIST-STATUS -- and for the ones asked about explicitly.
     *
     * @param  list<string>  $only
     * @return array<string, array{total: int, unread: int, uidnext: int}>
     */
    public function counts(array $only = []): array
    {
        $counts = [];

        foreach ($this->folders() as $folder) {
            if (! $folder['selectable'] || ($only !== [] && ! in_array($folder['id'], $only, true))) {
                continue;
            }

            if ($folder['total'] !== null && $only === []) {
                $counts[$folder['id']] = ['total' => $folder['total'], 'unread' => (int) $folder['unread'], 'uidnext' => (int) $folder['uidnext']];

                continue;
            }

            try {
                $status = $this->imap->status($folder['id']);
                $counts[$folder['id']] = ['total' => $status['messages'] ?? 0, 'unread' => $status['unseen'] ?? 0, 'uidnext' => $status['uidnext'] ?? 0];
            } catch (CommandFailed) {
                // A folder that vanished since it was listed.
            }
        }

        return $counts;
    }

    /**
     * The folder with a special purpose, e.g. "sent", or null.
     */
    public function roleFolder(string $role): ?string
    {
        foreach ($this->folders() as $folder) {
            if ($folder['role'] === $role) {
                return $folder['id'];
            }
        }

        return null;
    }

    /**
     * The folder with a special purpose, created when the mailbox has none.
     */
    public function ensureRoleFolder(string $role): string
    {
        $folder = $this->roleFolder($role);

        if ($folder !== null) {
            return $folder;
        }

        $name = ucfirst($role);

        try {
            $this->imap->create($name);
        } catch (CommandFailed $e) {
            if (stripos((string) $e->responseCode, 'ALREADYEXISTS') === false) {
                throw $e;
            }
        }

        $this->folders = null;

        return $name;
    }

    public function exists(string $folder): bool
    {
        return in_array($folder, array_column($this->folders(), 'id'), true);
    }

    /**
     * One page of a folder, newest first.
     *
     * @param  array{query?: string, body?: bool, unread?: bool, flagged?: bool}  $filter
     * @return array{total: int, page: int, pages: int, messages: list<array<string, mixed>>}
     */
    public function messages(string $folder, int $page, int $size, array $filter = []): array
    {
        $this->assertFolder($folder);
        $this->imap->select($folder);

        [$criteria, $utf8] = $this->criteria($filter);
        $uids = $this->imap->sortedUids($criteria, $utf8);
        $total = count($uids);
        $pages = max(1, (int) ceil($total / $size));
        $page = min(max(1, $page), $pages);
        $slice = array_slice($uids, ($page - 1) * $size, $size);

        $items = 'FLAGS INTERNALDATE RFC822.SIZE ENVELOPE BODYSTRUCTURE';

        if ($this->imap->has('PREVIEW')) {
            $items .= ' PREVIEW';
        }

        $fetched = $this->imap->fetch($slice, '(UID '.$items.')');
        $messages = [];

        foreach ($slice as $uid) {
            if (isset($fetched[$uid])) {
                $messages[] = $this->summary($fetched[$uid]);
            }
        }

        return ['total' => $total, 'page' => $page, 'pages' => $pages, 'messages' => $messages];
    }

    /**
     * Everything about one message but its body, which is rendered apart
     * (see {@see body()}). Opening a message marks it read.
     *
     * @return array<string, mixed>
     */
    public function message(string $folder, int $uid, bool $markSeen = true): array
    {
        $this->assertFolder($folder);
        $this->imap->select($folder, $markSeen);

        $fetched = $this->imap->fetch([$uid], '(UID FLAGS INTERNALDATE RFC822.SIZE BODYSTRUCTURE BODY.PEEK[HEADER])');
        $data = $fetched[$uid] ?? throw new UserError('This message no longer exists. It may have been moved or deleted.', 404);

        $headers = Header::parse((string) ($data['BODY[HEADER]'] ?? ''));
        $structure = Part::fromStructure($data['BODYSTRUCTURE'] ?? null);
        $layout = new Layout($structure);
        $flags = self::flags($data['FLAGS'] ?? []);

        if ($markSeen && ! $flags['seen']) {
            try {
                $this->imap->store([$uid], '+', ['\\Seen']);
                $flags['seen'] = true;
            } catch (CommandFailed) {
                // A read-only folder; the message stays unread, and opens.
            }
        }

        $header = static fn (string $name): ?string => $headers[$name][0] ?? null;
        $date = self::date($header('date'), (string) ($data['INTERNALDATE'] ?? ''));

        return [
            'folder' => $folder,
            'uid' => $uid,
            'subject' => Header::decode($header('subject')),
            'from' => Address::parseList($header('from')),
            'to' => Address::parseList(implode(', ', $headers['to'] ?? [])),
            'cc' => Address::parseList(implode(', ', $headers['cc'] ?? [])),
            'bcc' => Address::parseList(implode(', ', $headers['bcc'] ?? [])),
            'replyTo' => Address::parseList($header('reply-to')),
            'date' => $date,
            'size' => (int) ($data['RFC822.SIZE'] ?? 0),
            'flags' => $flags,
            'messageId' => $header('message-id'),
            'unsubscribe' => self::unsubscribe($header('list-unsubscribe')),
            'attachments' => array_map(self::attachment(...), $layout->attachments),
            'hasBody' => $layout->body !== [],
        ];
    }

    /**
     * The message's body as safe HTML, with images it carries itself linked
     * to where they can be fetched, and -- unless allowed -- nothing loaded
     * from elsewhere.
     *
     * @param  callable(string): string  $partUrl  builds the URL of a part by its section
     * @param  bool  $stylesheets  false where the HTML goes into the editor rather than a frame
     * @return array{html: string, blocked: int, plain: bool}
     */
    public function body(string $folder, int $uid, bool $allowRemote, callable $partUrl, bool $preferHtml = true, bool $stylesheets = true): array
    {
        $this->assertFolder($folder);
        $this->imap->select($folder);

        [$structure, $texts] = $this->texts($uid, $preferHtml);
        $layout = new Layout($structure, $preferHtml);
        $cid = static function (string $id) use ($layout, $partUrl): ?string {
            $part = $layout->inline[trim($id, '<> ')] ?? null;

            return $part === null ? null : $partUrl($part->section);
        };

        $sanitizer = new Sanitizer($allowRemote, $cid, true, $stylesheets);
        $html = [];
        $blocked = 0;
        $plain = true;

        foreach ($texts as [$part, $text]) {
            if ($part->subtype === 'html') {
                $clean = $sanitizer->clean($text);
                $html[] = $clean['html'];
                $blocked += $clean['blocked'];
                $plain = false;
            } else {
                $html[] = Text::toHtml($text, strtolower($part->parameters['format'] ?? '') === 'flowed', strtolower($part->parameters['delsp'] ?? '') === 'yes');
            }
        }

        return ['html' => implode('<hr class="part">', $html), 'blocked' => $blocked, 'plain' => $plain];
    }

    /**
     * The message's body for quoting in a reply or a forward: HTML with
     * nothing loaded from anywhere, and the same as plain text.
     *
     * @return array{html: string, text: string}
     */
    public function quotable(string $folder, int $uid): array
    {
        $this->assertFolder($folder);
        $this->imap->select($folder);

        [, $texts] = $this->texts($uid, true);
        // The original's stylesheet would restyle the whole reply.
        $sanitizer = new Sanitizer(false, null, true, false);
        $html = [];
        $text = [];

        foreach ($texts as [$part, $content]) {
            if ($part->subtype === 'html') {
                $clean = $sanitizer->clean($content)['html'];
                $html[] = $clean;
                $text[] = Text::fromHtml($clean);
            } else {
                // The editor has no pre-wrap styling for the "plain" block,
                // so its line breaks are spelled out.
                $html[] = str_replace("\n", '<br>', Text::toHtml($content, strtolower($part->parameters['format'] ?? '') === 'flowed'));
                $text[] = $content;
            }
        }

        return ['html' => implode('<br>', $html), 'text' => trim(implode("\n\n", $text))];
    }

    /**
     * The raw headers of a message, name => values, for building a reply.
     *
     * @return array{headers: array<string, list<string>>, structure: Part, date: ?string}
     */
    public function headers(string $folder, int $uid): array
    {
        $this->assertFolder($folder);
        $this->imap->select($folder);
        $fetched = $this->imap->fetch([$uid], '(UID INTERNALDATE BODYSTRUCTURE BODY.PEEK[HEADER])');
        $data = $fetched[$uid] ?? throw new UserError('This message no longer exists. It may have been moved or deleted.', 404);
        $headers = Header::parse((string) ($data['BODY[HEADER]'] ?? ''));

        return [
            'headers' => $headers,
            'structure' => Part::fromStructure($data['BODYSTRUCTURE'] ?? null),
            'date' => self::date($headers['date'][0] ?? null, (string) ($data['INTERNALDATE'] ?? '')),
        ];
    }

    /**
     * A part of a message, decoded, and what it is.
     *
     * @return array{part: Part, name: string}
     */
    public function part(string $folder, int $uid, string $section): array
    {
        $this->assertFolder($folder);
        $this->imap->select($folder);

        $fetched = $this->imap->fetch([$uid], '(UID BODYSTRUCTURE)');
        $structure = Part::fromStructure($fetched[$uid]['BODYSTRUCTURE'] ?? throw new UserError('This message no longer exists. It may have been moved or deleted.', 404));
        $part = $structure->find($section);

        if ($part === null || $part->isMultipart()) {
            throw new UserError('This attachment no longer exists.', 404);
        }

        return ['part' => $part, 'name' => self::partName($part)];
    }

    /**
     * Stream a part, decoded, to the sink. The whole message is section "".
     *
     * @param  callable(string): void  $sink
     */
    public function streamPart(int $uid, string $section, string $encoding, callable $sink): void
    {
        $decoder = new Decoder($encoding, $sink);

        if (! $this->imap->stream($uid, $section, $decoder->write(...))) {
            throw new UserError('This attachment no longer exists.', 404);
        }

        $decoder->finish();
    }

    /**
     * A part, decoded, in memory: for carrying attachments over into a new
     * message.
     *
     * @return array{name: string, type: string, data: string}
     */
    public function partContents(string $folder, int $uid, string $section): array
    {
        $info = $this->part($folder, $uid, $section);
        $data = '';
        $this->streamPart($uid, $section, $info['part']->encoding, static function (string $chunk) use (&$data): void {
            $data .= $chunk;
        });

        return ['name' => $info['name'], 'type' => $info['part']->isAttachedMessage() ? 'message/rfc822' : $info['part']->mimeType(), 'data' => $data];
    }

    /**
     * @param  list<int>  $uids
     */
    public function flag(string $folder, array $uids, string $flag, bool $on): void
    {
        $imapFlag = match ($flag) {
            'seen' => '\\Seen',
            'flagged' => '\\Flagged',
            'answered' => '\\Answered',
            'forwarded' => '$Forwarded',
            'junk' => '$Junk',
            default => throw new UserError('Unknown flag.'),
        };

        $this->assertFolder($folder);
        $this->imap->select($folder, true);
        $this->imap->store($uids, $on ? '+' : '-', [$imapFlag]);
    }

    /**
     * Mark every message in a folder read.
     */
    public function markAllRead(string $folder): void
    {
        $this->assertFolder($folder);
        $this->imap->select($folder, true);
        $this->imap->store($this->imap->search(['UNSEEN']), '+', ['\\Seen']);
    }

    /**
     * @param  list<int>  $uids
     */
    public function move(string $folder, array $uids, string $target): void
    {
        $this->assertFolder($folder);
        $this->assertFolder($target);

        if ($folder === $target) {
            return;
        }

        $this->imap->select($folder, true);
        $this->imap->move($uids, $target);
    }

    /**
     * Delete messages: into the trash, or for good when they are in the
     * trash (or the junk folder) already.
     *
     * @param  list<int>  $uids
     * @return bool whether they were deleted for good
     */
    public function delete(string $folder, array $uids): bool
    {
        $this->assertFolder($folder);
        $role = $this->role($folder);

        if (in_array($role, ['trash', 'junk'], true)) {
            $this->imap->select($folder, true);
            $this->imap->expunge($uids);

            return true;
        }

        $trash = $this->ensureRoleFolder('trash');
        $this->imap->select($folder, true);
        $this->imap->move($uids, $trash);

        return false;
    }

    /**
     * Delete every message in a folder for good. Only the trash and the junk
     * folder can be emptied, so a slip of the mouse cannot empty the inbox.
     */
    public function empty(string $folder): void
    {
        $this->assertFolder($folder);

        if (! in_array($this->role($folder), ['trash', 'junk'], true)) {
            throw new UserError('Only the trash and the junk folder can be emptied.', 403);
        }

        $this->imap->select($folder, true);
        $this->imap->expunge($this->imap->search(['ALL']));
    }

    /**
     * Create a folder, below another one or at the top.
     */
    public function createFolder(string $name, ?string $parent): string
    {
        $name = self::validFolderName($name);

        if ($parent !== null && $parent !== '') {
            $this->assertFolder($parent);
            $name = $parent.($this->delimiter($parent) ?? '.').self::encodeName($name);
        } else {
            $name = self::encodeName($name);
        }

        if ($this->exists($name)) {
            throw new UserError('A folder with this name already exists.');
        }

        $this->imap->create($name);
        $this->folders = null;

        return $name;
    }

    public function renameFolder(string $folder, string $name): string
    {
        $this->assertFolder($folder);

        if ($this->role($folder) !== null) {
            throw new UserError('This folder cannot be renamed.', 403);
        }

        $name = self::validFolderName($name);
        $delimiter = $this->delimiter($folder);
        $position = $delimiter === null ? false : strrpos($folder, $delimiter);
        $target = ($position === false ? '' : substr($folder, 0, $position + 1)).self::encodeName($name);

        if ($target === $folder) {
            return $folder;
        }

        if ($this->exists($target)) {
            throw new UserError('A folder with this name already exists.');
        }

        $this->imap->rename($folder, $target);
        $this->folders = null;

        return $target;
    }

    public function deleteFolder(string $folder): void
    {
        $this->assertFolder($folder);

        if ($this->role($folder) !== null) {
            throw new UserError('This folder cannot be deleted.', 403);
        }

        foreach ($this->folders() as $candidate) {
            if ($candidate['parent'] === $folder) {
                throw new UserError('Delete or move the folders inside this one first.');
            }
        }

        $this->imap->subscribe($folder, false);
        $this->imap->delete($folder);
        $this->folders = null;
    }

    /**
     * @return array{used: int, limit: int}|null
     */
    public function quota(): ?array
    {
        return $this->imap->quota();
    }

    /**
     * Add a message to a folder, returning its UID where the server says.
     *
     * @param  list<string>  $flags
     */
    public function append(string $folder, string $message, array $flags): ?int
    {
        return $this->imap->append($folder, $message, $flags);
    }

    /**
     * Delete one message for good, wherever it is -- a draft replaced by a
     * newer version of itself.
     */
    public function discard(string $folder, int $uid): void
    {
        $this->assertFolder($folder);
        $this->imap->select($folder, true);
        $this->imap->expunge([$uid]);
    }

    public function role(string $folder): ?string
    {
        foreach ($this->folders() as $candidate) {
            if ($candidate['id'] === $folder) {
                return $candidate['role'];
            }
        }

        return null;
    }

    public function assertFolder(string $folder): void
    {
        if (! $this->exists($folder)) {
            throw new UserError('This folder no longer exists.', 404);
        }
    }

    /**
     * The display name of a server folder name.
     */
    public static function decodeName(string $name): string
    {
        if (! str_contains($name, '&')) {
            return $name;
        }

        $decoded = @mb_convert_encoding($name, 'UTF-8', 'UTF7-IMAP');

        return is_string($decoded) && mb_check_encoding($decoded, 'UTF-8') ? $decoded : $name;
    }

    public static function encodeName(string $name): string
    {
        return (string) mb_convert_encoding($name, 'UTF7-IMAP', 'UTF-8');
    }

    /**
     * Which body parts to show, fetched and converted to UTF-8.
     *
     * @return array{0: Part, 1: list<array{0: Part, 1: string}>}
     */
    private function texts(int $uid, bool $preferHtml): array
    {
        $fetched = $this->imap->fetch([$uid], '(UID BODYSTRUCTURE)');
        $structure = Part::fromStructure($fetched[$uid]['BODYSTRUCTURE'] ?? throw new UserError('This message no longer exists. It may have been moved or deleted.', 404));
        $layout = new Layout($structure, $preferHtml);

        if ($layout->body === []) {
            return [$structure, []];
        }

        $items = implode(' ', array_map(
            static fn (Part $part): string => 'BODY.PEEK['.$part->section.']<0.'.self::BODY_LIMIT.'>',
            $layout->body,
        ));

        $data = $this->imap->fetch([$uid], '(UID '.$items.')')[$uid] ?? [];
        $texts = [];

        foreach ($layout->body as $part) {
            $raw = $data['BODY['.$part->section.']'] ?? '';
            $decoded = Decoder::decode(is_string($raw) ? $raw : '', $part->encoding);
            $charset = $part->charset();

            // An HTML part may name its charset only in a <meta> tag.
            if ($charset === null && $part->subtype === 'html' && preg_match('/<meta[^>]+charset=["\']?([a-z0-9_:.-]+)/i', $decoded, $match) === 1) {
                $charset = $match[1];
            }

            $texts[] = [$part, Charset::toUtf8($decoded, $charset)];
        }

        return [$structure, $texts];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function summary(array $data): array
    {
        $envelope = is_array($data['ENVELOPE'] ?? null) ? $data['ENVELOPE'] : [];
        $structure = Part::fromStructure($data['BODYSTRUCTURE'] ?? null);

        return [
            'uid' => (int) $data['UID'],
            'subject' => Header::decode(is_string($envelope[1] ?? null) ? $envelope[1] : ''),
            'from' => Address::fromEnvelope($envelope[2] ?? null),
            'to' => Address::fromEnvelope($envelope[5] ?? null),
            'date' => self::date(is_string($envelope[0] ?? null) ? $envelope[0] : null, (string) ($data['INTERNALDATE'] ?? '')),
            'size' => (int) ($data['RFC822.SIZE'] ?? 0),
            'flags' => self::flags($data['FLAGS'] ?? []),
            'attachments' => Layout::hasAttachments($structure),
            'preview' => is_string($data['PREVIEW'] ?? null) ? mb_substr(Charset::toUtf8($data['PREVIEW'], 'UTF-8'), 0, 200) : null,
        ];
    }

    /**
     * @param  array{query?: string, body?: bool, unread?: bool, flagged?: bool}  $filter
     * @return array{0: list<string|Literal>, 1: bool}
     */
    private function criteria(array $filter): array
    {
        $criteria = [];
        $query = trim((string) ($filter['query'] ?? ''));

        if (! empty($filter['unread'])) {
            $criteria[] = 'UNSEEN';
        }

        if (! empty($filter['flagged'])) {
            $criteria[] = 'FLAGGED';
        }

        if ($query !== '') {
            $value = $this->imap->astring(mb_substr($query, 0, 200));
            $fields = ['FROM', 'TO', 'CC', 'SUBJECT'];

            if (! empty($filter['body'])) {
                $fields[] = 'BODY';
            }

            // OR takes two keys; three fields are OR a (OR b c).
            $search = [array_pop($fields), $value];

            while ($fields !== []) {
                $search = ['OR', array_pop($fields), $value, ...$search];
            }

            array_push($criteria, ...$search);
        }

        return [$criteria, preg_match('/[^\x20-\x7e]/', $query) === 1];
    }

    /**
     * @param  list<array{name: string, delimiter: mixed, attributes: list<string>, status: mixed}>  $raw
     * @return array<string, string>
     */
    private function assignRoles(array $raw): array
    {
        $roles = [];

        foreach (self::ROLES as $role => $definition) {
            foreach ($raw as $folder) {
                if (in_array(strtolower($definition['attribute']), array_map(strtolower(...), $folder['attributes']), true)) {
                    $roles[$folder['name']] ??= $role;

                    continue 2;
                }
            }

            // No folder is marked: go by name, top-level first, then under
            // INBOX the way cPanel-style servers keep everything.
            foreach ([false, true] as $nested) {
                foreach ($raw as $folder) {
                    $delimiter = is_string($folder['delimiter']) ? $folder['delimiter'] : '.';
                    $name = $folder['name'];

                    if ($nested) {
                        $prefix = 'INBOX'.$delimiter;

                        if (stripos($name, $prefix) !== 0) {
                            continue;
                        }

                        $name = substr($name, strlen($prefix));
                    }

                    if (! isset($roles[$folder['name']]) && in_array(mb_strtolower(self::decodeName($name)), $definition['names'], true)) {
                        $roles[$folder['name']] = $role;

                        continue 3;
                    }
                }
            }
        }

        return $roles;
    }

    /**
     * @param  list<array<string, mixed>>  $folders
     * @return list<array<string, mixed>>
     */
    private static function order(array $folders): array
    {
        $rank = ['inbox' => 0, 'drafts' => 1, 'sent' => 2, 'archive' => 3, 'junk' => 4, 'trash' => 5];
        $byId = array_column($folders, null, 'id');
        $children = [];

        foreach ($folders as $folder) {
            $children[$folder['parent'] ?? ''][] = $folder['id'];
        }

        $sortKey = static fn (string $id): array => [
            $rank[$byId[$id]['role'] ?? ''] ?? 10,
            mb_strtolower($byId[$id]['name']),
        ];

        $ordered = [];
        $visit = static function (string $parent, int $depth) use (&$visit, &$ordered, &$children, $byId, $sortKey): void {
            $ids = $children[$parent] ?? [];
            usort($ids, static fn (string $a, string $b): int => $sortKey($a) <=> $sortKey($b));

            foreach ($ids as $id) {
                $ordered[] = [...$byId[$id], 'depth' => $depth];
                $visit($id, $depth + 1);
            }
        };

        $visit('', 0);

        return $ordered;
    }

    private function delimiter(string $folder): ?string
    {
        foreach ($this->folders() as $candidate) {
            if ($candidate['id'] === $folder) {
                return $candidate['delimiter'];
            }
        }

        return null;
    }

    private static function validFolderName(string $name): string
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 100 || preg_match('/[\x00-\x1f\x7f%*\/\\\\.]/u', $name) === 1) {
            throw new UserError('Folder names cannot be empty or contain / \\ . % or *.');
        }

        return $name;
    }

    /**
     * @return array{seen: bool, flagged: bool, answered: bool, forwarded: bool, draft: bool}
     */
    private static function flags(mixed $flags): array
    {
        $flags = is_array($flags) ? array_map(static fn (mixed $flag): string => strtolower((string) $flag), $flags) : [];

        return [
            'seen' => in_array('\\seen', $flags, true),
            'flagged' => in_array('\\flagged', $flags, true),
            'answered' => in_array('\\answered', $flags, true),
            'forwarded' => in_array('$forwarded', $flags, true),
            'draft' => in_array('\\draft', $flags, true),
        ];
    }

    /**
     * An ISO 8601 date: the Date header where it parses, the time the server
     * received the message where it does not.
     */
    private static function date(?string $header, string $internal): ?string
    {
        foreach ([$header, $internal] as $candidate) {
            if ($candidate === null || trim($candidate) === '') {
                continue;
            }

            // Comments such as "(UTC)" or "(CEST)" trip the parser up.
            $candidate = trim((string) preg_replace('/\([^)]*\)/', '', $candidate));

            try {
                return (new DateTimeImmutable($candidate))->format(DATE_ATOM);
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }

    /**
     * An unsubscribe link from List-Unsubscribe: the web one where there is
     * one, a mailto: otherwise.
     */
    private static function unsubscribe(?string $header): ?string
    {
        if ($header === null || preg_match_all('/<([^>]+)>/', $header, $matches) === 0) {
            return null;
        }

        $mailto = null;

        foreach ($matches[1] as $url) {
            $url = trim($url);

            if (preg_match('#^https://#i', $url) === 1) {
                return $url;
            }

            if (stripos($url, 'mailto:') === 0) {
                $mailto ??= $url;
            }
        }

        return $mailto;
    }

    /**
     * @return array{part: string, name: string, type: string, size: int, image: bool}
     */
    private static function attachment(Part $part): array
    {
        return [
            'part' => $part->section,
            'name' => self::partName($part),
            'type' => $part->isAttachedMessage() ? 'message/rfc822' : $part->mimeType(),
            // Encoded size; base64 is a third larger than what it carries.
            'size' => $part->encoding === 'base64' ? (int) floor($part->size * 3 / 4) : $part->size,
            'image' => in_array($part->mimeType(), ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true),
        ];
    }

    public static function partName(Part $part): string
    {
        $name = $part->filename();

        if ($name !== null) {
            return $name;
        }

        return match (true) {
            $part->isAttachedMessage() => 'message.eml',
            $part->mimeType() === 'text/calendar' => 'invite.ics',
            $part->type === 'text' => 'attachment.txt',
            $part->type === 'image' => 'image.'.($part->subtype === 'jpeg' ? 'jpg' : $part->subtype),
            default => 'attachment',
        };
    }

    /**
     * Why an IMAP failure happened, in terms the user can act on, or null
     * when it is not theirs to fix.
     */
    public static function explain(Throwable $e): ?UserError
    {
        if ($e instanceof UserError) {
            return $e;
        }

        if ($e instanceof CommandFailed) {
            $code = strtoupper((string) $e->responseCode);

            return match (true) {
                str_starts_with($code, 'OVERQUOTA') => new UserError('Your mailbox is full. Delete some messages, and empty the trash, to make room.', 507),
                str_starts_with($code, 'ALREADYEXISTS') => new UserError('A folder with this name already exists.'),
                str_starts_with($code, 'NONEXISTENT') => new UserError('This folder no longer exists.', 404),
                str_starts_with($code, 'LIMIT') => new UserError('The mail server refused this: :reason', 422, ['reason' => $e->text]),
                default => new UserError('The mail server refused this: :reason', 422, ['reason' => $e->text]),
            };
        }

        if ($e instanceof RuntimeException && (str_contains($e->getMessage(), 'Could not connect') || str_contains($e->getMessage(), 'stopped answering') || str_contains($e->getMessage(), 'closed the connection'))) {
            error_log('mail-simply: '.$e->getMessage());

            return new UserError('The mail server cannot be reached right now. Try again in a moment.', 503);
        }

        return null;
    }
}
