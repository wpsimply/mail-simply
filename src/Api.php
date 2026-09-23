<?php

declare(strict_types=1);

namespace MailSimply;

use MailSimply\Html\Sanitizer;
use Throwable;

/**
 * The JSON API behind the interface: one action per request.
 *
 * Reads are GET, changes are POST with a JSON body and the session's CSRF
 * token in the X-CSRF-Token header. Every action opens the session's own
 * mailbox and nothing else; the folder and message a request names travel
 * in it, and are checked against the mailbox before use.
 */
final class Api
{
    private const array READS = ['session', 'folders', 'messages', 'message', 'compose', 'contacts', 'poll'];

    private const array WRITES = [
        'flag', 'move', 'delete', 'empty', 'mark-all-read', 'folder-create', 'folder-rename', 'folder-delete',
        'send', 'draft', 'discard-draft', 'settings', 'trust', 'discard-upload', 'forget-contact',
    ];

    private ?Mailbox $mailbox = null;

    public function __construct(
        private readonly Config $config,
        private readonly Session $session,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>|list<mixed>
     */
    public function handle(string $method, string $action, array $query, array $body, ?string $csrfToken = null): array
    {
        $grant = $this->session->grant();

        if ($grant === null) {
            throw new UserError('Your session has ended. Sign in again.', 401);
        }

        $isRead = in_array($action, self::READS, true);

        if (! $isRead && ! in_array($action, self::WRITES, true)) {
            throw new UserError('Unknown action.', 404);
        }

        if ($isRead && $method !== 'GET') {
            throw new UserError('Method not allowed.', 405);
        }

        if (! $isRead) {
            if ($method !== 'POST') {
                throw new UserError('Method not allowed.', 405);
            }

            if (! $this->session->verifyCsrf($csrfToken)) {
                throw new UserError('Your session token is out of date. Reload the page.', 419);
            }
        }

        $uploadKey = $this->session->uploadKey();
        $this->session->release();

        try {
            return $this->dispatch($grant, $uploadKey, $action, $query, $body);
        } catch (Throwable $e) {
            throw Mailbox::explain($e) ?? $e;
        } finally {
            $this->mailbox?->close();
            $this->mailbox = null;
        }
    }

    /**
     * @param  array{address: string, name: string, master: bool, password: ?string}  $grant
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>|list<mixed>
     */
    private function dispatch(array $grant, string $uploadKey, string $action, array $query, array $body): array
    {
        $preferences = self::preferences($this->config, $grant['address']);

        // Settings and addresses need no mail server; answer them without one.
        switch ($action) {
            case 'contacts':
                return $preferences->contacts((string) ($query['q'] ?? ''));

            case 'settings':
                $signature = (string) ($body['signature'] ?? $preferences->signature());
                $preferences->update([
                    'name' => array_key_exists('name', $body) ? (string) $body['name'] : $preferences->name(),
                    'signature' => trim(strip_tags($signature)) === '' && ! str_contains($signature, '<img') ? '' : (new Sanitizer(true, null, true, false))->clean($signature)['html'],
                    'html' => (bool) ($body['html'] ?? $preferences->html()),
                    'language' => array_key_exists('language', $body) ? $body['language'] : $preferences->language(),
                    'trusted' => is_array($body['trusted'] ?? null) ? $body['trusted'] : $preferences->trusted(),
                ]);

                return $this->settings($preferences, $grant);

            case 'trust':
                $preferences->trust((string) ($body['sender'] ?? ''), (bool) ($body['domain'] ?? false));

                return ['trusted' => $preferences->trusted()];

            case 'forget-contact':
                $preferences->forget((string) ($body['email'] ?? ''));

                return [];

            case 'discard-upload':
                (new Uploads($this->config->string('storage.uploads'), $uploadKey))->delete((string) ($body['id'] ?? ''));

                return [];
        }

        $mailbox = $this->mailbox = Mailbox::open($this->config, $grant);
        $folder = (string) ($query['folder'] ?? $body['folder'] ?? '');
        $uids = self::uids($body['uids'] ?? []);

        switch ($action) {
            case 'session':
                return [
                    'address' => $grant['address'],
                    'settings' => $this->settings($preferences, $grant),
                    'folders' => $this->folders($mailbox),
                    'quota' => $mailbox->quota(),
                    'limits' => [
                        'pageSize' => $this->config->int('limits.page_size'),
                        'attachments' => $this->config->int('limits.attachments'),
                        'recipients' => $this->config->int('limits.recipients'),
                    ],
                ];

            case 'folders':
                return ['folders' => $this->folders($mailbox), 'quota' => $mailbox->quota()];

            case 'poll':
                // Only counts, cheaply: enough to tell the open folder changed
                // and to keep the unread badges honest.
                return ['counts' => $mailbox->counts()];

            case 'messages':
                return $mailbox->messages($folder, (int) ($query['page'] ?? 1), max(10, min(200, $this->config->int('limits.page_size'))), [
                    'query' => (string) ($query['q'] ?? ''),
                    'body' => ($query['body'] ?? '') === '1',
                    'unread' => ($query['unread'] ?? '') === '1',
                    'flagged' => ($query['flagged'] ?? '') === '1',
                ]);

            case 'message':
                $message = $mailbox->message($folder, (int) ($query['uid'] ?? 0));
                $sender = $message['from'][0]['email'] ?? '';
                $message['trusted'] = $sender !== '' && $preferences->trusts($sender);

                return $message;

            case 'compose':
                $compose = $this->compose($mailbox, $preferences, $grant, $uploadKey);
                $mode = (string) ($query['mode'] ?? '');

                if (! in_array($mode, ['reply', 'replyAll', 'forward', 'draft'], true)) {
                    throw new UserError('Unknown action.', 404);
                }

                return $compose->prefill($mode, $folder, (int) ($query['uid'] ?? 0), (string) ($query['tz'] ?? ''));

            case 'flag':
                $mailbox->flag($folder, $uids, (string) ($body['flag'] ?? ''), (bool) ($body['on'] ?? true));

                return [];

            case 'mark-all-read':
                $mailbox->markAllRead($folder);

                return [];

            case 'move':
                $mailbox->move($folder, $uids, (string) ($body['target'] ?? ''));

                return [];

            case 'delete':
                return ['permanent' => $mailbox->delete($folder, $uids)];

            case 'empty':
                $mailbox->empty($folder);

                return [];

            case 'folder-create':
                $parent = $body['parent'] ?? null;

                return ['id' => $mailbox->createFolder((string) ($body['name'] ?? ''), is_string($parent) ? $parent : null)];

            case 'folder-rename':
                return ['id' => $mailbox->renameFolder($folder, (string) ($body['name'] ?? ''))];

            case 'folder-delete':
                $mailbox->deleteFolder($folder);

                return [];

            case 'send':
                return $this->compose($mailbox, $preferences, $grant, $uploadKey)->send($body);

            case 'draft':
                return $this->compose($mailbox, $preferences, $grant, $uploadKey)->saveDraft($body);

            case 'discard-draft':
                $this->compose($mailbox, $preferences, $grant, $uploadKey)->discardDraft($body['draft'] ?? null);

                return [];
        }

        throw new UserError('Unknown action.', 404);
    }

    public static function preferences(Config $config, string $address): Preferences
    {
        return new Preferences($config->string('storage.users'), $address);
    }

    /**
     * @param  array{address: string, name: string, master: bool, password: ?string}  $grant
     */
    private function compose(Mailbox $mailbox, Preferences $preferences, array $grant, string $uploadKey): Compose
    {
        $lang = new Lang(Lang::negotiate($preferences->language(), $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null, $this->config->string('language')));

        return new Compose(
            $this->config,
            $mailbox,
            $preferences,
            new Uploads($this->config->string('storage.uploads'), $uploadKey),
            $lang,
            $grant,
        );
    }

    /**
     * @param  array{address: string, name: string, master: bool, password: ?string}  $grant
     * @return array<string, mixed>
     */
    private function settings(Preferences $preferences, array $grant): array
    {
        return [
            'name' => $preferences->name() ?? $grant['name'],
            'signature' => $preferences->signature(),
            'html' => $preferences->html(),
            'language' => $preferences->language(),
            'trusted' => $preferences->trusted(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function folders(Mailbox $mailbox): array
    {
        $folders = $mailbox->folders();
        $counts = $mailbox->counts();

        return array_map(static fn (array $folder): array => [
            ...$folder,
            'total' => $counts[$folder['id']]['total'] ?? $folder['total'],
            'unread' => $counts[$folder['id']]['unread'] ?? $folder['unread'],
            'uidnext' => $counts[$folder['id']]['uidnext'] ?? $folder['uidnext'],
        ], $folders);
    }

    /**
     * @return list<int>
     */
    private static function uids(mixed $uids): array
    {
        if (! is_array($uids)) {
            return [];
        }

        $valid = array_values(array_filter(array_map(intval(...), $uids), static fn (int $uid): bool => $uid > 0));

        if (count($valid) > 5000) {
            throw new UserError('Too many messages at once.');
        }

        return $valid;
    }
}
