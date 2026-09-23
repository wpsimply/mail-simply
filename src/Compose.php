<?php

declare(strict_types=1);

namespace MailSimply;

use DateTimeImmutable;
use DateTimeZone;
use MailSimply\Html\Sanitizer;
use MailSimply\Html\Text;
use MailSimply\Imap\CommandFailed;
use MailSimply\Mime\Address;
use MailSimply\Mime\Builder;
use MailSimply\Mime\Header;
use MailSimply\Mime\Layout;
use MailSimply\Mime\Part;
use MailSimply\Smtp\Client as Smtp;
use MailSimply\Smtp\Rejected;
use RuntimeException;
use Throwable;

/**
 * Writing mail: what a reply, a forward or a reopened draft starts out as,
 * and sending or saving what the user wrote.
 *
 * Attachments are referred to by id. "u:<id>" is a file this session
 * uploaded; "s:<token>" is a part of a message already in the mailbox -- the
 * original's attachments on a forward, a draft's own on reopening it -- and
 * is fetched from the server only when the message is built.
 */
final class Compose
{
    /**
     * The most bytes an image embedded in a draft may have to be turned back
     * into a data: URL for the editor.
     */
    private const int EMBED_LIMIT = 2097152;

    /**
     * @param  array{address: string, name: string, master: bool, password: ?string}  $grant
     */
    public function __construct(
        private readonly Config $config,
        private readonly Mailbox $mailbox,
        private readonly Preferences $preferences,
        private readonly Uploads $uploads,
        private readonly Lang $lang,
        private readonly array $grant,
    ) {}

    /**
     * What the editor starts with.
     *
     * @return array<string, mixed>
     */
    public function prefill(string $mode, string $folder, int $uid, string $timezone): array
    {
        $source = $this->mailbox->headers($folder, $uid);
        $headers = $source['headers'];
        $header = static fn (string $name): ?string => $headers[$name][0] ?? null;
        $subject = Header::decode($header('subject'));
        $from = Address::parseList($header('from'));
        $to = Address::parseList(implode(', ', $headers['to'] ?? []));
        $cc = Address::parseList(implode(', ', $headers['cc'] ?? []));
        $messageId = trim((string) $header('message-id'));

        if ($mode === 'draft') {
            return $this->reopen($folder, $uid, $source['structure'], $headers);
        }

        $quote = $this->mailbox->quotable($folder, $uid);
        $date = $this->formatDate($source['date'], $timezone);
        $sender = $from[0] ?? ['name' => '', 'email' => ''];
        $senderLabel = $sender['name'] !== '' ? $sender['name'] : $sender['email'];

        if ($mode === 'forward') {
            $lines = [
                $this->lang->get('---------- Forwarded message ----------'),
                $this->lang->get('From:').' '.Address::display($from),
                $this->lang->get('Date:').' '.$date,
                $this->lang->get('Subject:').' '.$subject,
                $this->lang->get('To:').' '.Address::display($to),
            ];

            if ($cc !== []) {
                $lines[] = $this->lang->get('Cc:').' '.Address::display($cc);
            }

            $escaped = array_map(static fn (string $line): string => htmlspecialchars($line, ENT_QUOTES, 'UTF-8'), $lines);

            return [
                'to' => [],
                'cc' => [],
                'bcc' => [],
                'subject' => self::prefixed($subject, 'Fwd:', '/^\s*(fwd?|továbbítás|továbbított)\s*:/i'),
                'quoteHtml' => '<p>'.implode('<br>', $escaped).'</p>'.$quote['html'],
                'quoteText' => implode("\n", $lines)."\n\n".$quote['text'],
                'inReplyTo' => null,
                'references' => null,
                'attachments' => $this->sources($folder, $uid, (new Layout($source['structure']))->attachments),
                'source' => ['folder' => $folder, 'uid' => $uid, 'mode' => 'forward'],
                'draft' => null,
            ];
        }

        $self = strtolower($this->mailbox->address);
        $replyTo = Address::parseList($header('reply-to'));
        $recipients = $replyTo !== [] ? $replyTo : $from;

        // Replying to something this mailbox sent goes back to whoever it
        // was sent to, not to itself.
        if (count($recipients) === 1 && strtolower($recipients[0]['email']) === $self && $to !== []) {
            $recipients = $to;
        }

        $copies = [];

        if ($mode === 'replyAll') {
            $taken = array_map(static fn (array $address): string => strtolower($address['email']), $recipients);
            $taken[] = $self;

            foreach ([...$to, ...$cc] as $address) {
                $email = strtolower($address['email']);

                if (! in_array($email, $taken, true)) {
                    $copies[] = $address;
                    $taken[] = $email;
                }
            }
        }

        $references = trim(implode(' ', array_slice(preg_split('/\s+/', trim(($header('references') ?? '').' '.$messageId)) ?: [], -20)));
        $wrote = $this->lang->get('On :date, :name wrote:', ['date' => $date, 'name' => $senderLabel]);

        return [
            'to' => $recipients,
            'cc' => $copies,
            'bcc' => [],
            'subject' => self::prefixed($subject, 'Re:', '/^\s*(re|aw|vá|válasz)\s*:/i'),
            'quoteHtml' => '<p>'.htmlspecialchars($wrote, ENT_QUOTES, 'UTF-8').'</p><blockquote type="cite">'.$quote['html'].'</blockquote>',
            'quoteText' => $wrote."\n".implode("\n", array_map(
                static fn (string $line): string => $line === '' || str_starts_with($line, '>') ? '>'.$line : '> '.$line,
                explode("\n", $quote['text']),
            )),
            'inReplyTo' => $messageId !== '' ? $messageId : null,
            'references' => $references !== '' ? $references : null,
            'attachments' => [],
            'source' => ['folder' => $folder, 'uid' => $uid, 'mode' => 'reply'],
            'draft' => null,
        ];
    }

    /**
     * Send a message, keep a copy in the sent folder, and tidy up after it:
     * the draft it was written from goes, and the message it answered is
     * marked answered.
     *
     * @param  array<string, mixed>  $input
     * @return array{sent: bool, savedToSent: bool}
     */
    public function send(array $input): array
    {
        $message = $this->build($input, true);

        if ($message['builder']->recipients() === []) {
            throw new UserError('Add at least one recipient.');
        }

        $this->submit($message['builder']);

        $savedToSent = true;

        try {
            $sent = $this->mailbox->ensureRoleFolder('sent');
            $this->mailbox->append($sent, $message['builder']->build(true), ['\\Seen']);
        } catch (Throwable $e) {
            // Sent is sent; a missing copy is reported, never a failure.
            error_log('mail-simply: saving to the sent folder failed: '.$e->getMessage());
            $savedToSent = false;
        }

        $this->afterSending($input);
        $this->preferences->remember([...$message['to'], ...$message['cc'], ...$message['bcc']]);

        foreach ($message['uploads'] as $id) {
            $this->uploads->delete($id);
        }

        return ['sent' => true, 'savedToSent' => $savedToSent];
    }

    /**
     * Save a draft, replacing the version saved before it.
     *
     * @param  array<string, mixed>  $input
     * @return array{folder: string, uid: ?int}
     */
    public function saveDraft(array $input): array
    {
        $message = $this->build($input, false);
        $drafts = $this->mailbox->ensureRoleFolder('drafts');
        $builder = $message['builder'];
        $uid = $this->mailbox->append($drafts, $builder->build(true), ['\\Draft', '\\Seen']);

        if ($uid === null) {
            $this->mailbox->imap()->select($drafts);
            $found = $this->mailbox->imap()->search(['HEADER', 'Message-ID', $this->mailbox->imap()->astring($builder->messageId())]);
            $uid = $found === [] ? null : max($found);
        }

        $this->discardPrevious($input['draft'] ?? null, $drafts, $uid);

        return ['folder' => $drafts, 'uid' => $uid];
    }

    /**
     * The sender a message goes out as.
     *
     * @return array{name: string, email: string}
     */
    public function sender(): array
    {
        return ['name' => $this->preferences->name() ?? $this->grant['name'], 'email' => $this->mailbox->address];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{builder: Builder, to: list<array{name: string, email: string}>, cc: list<array{name: string, email: string}>, bcc: list<array{name: string, email: string}>, uploads: list<string>}
     */
    private function build(array $input, bool $sending): array
    {
        $to = $this->recipients($input['to'] ?? []);
        $cc = $this->recipients($input['cc'] ?? []);
        $bcc = $this->recipients($input['bcc'] ?? []);

        if (count($to) + count($cc) + count($bcc) > $this->config->int('limits.recipients')) {
            throw new UserError('A message can go to at most :count recipients.', 422, ['count' => $this->config->int('limits.recipients')]);
        }

        $subject = trim(str_replace(["\r", "\n"], ' ', (string) ($input['subject'] ?? '')));
        $html = is_string($input['html'] ?? null) ? $input['html'] : null;

        if ($html !== null) {
            // What the editor sends is the user's own writing, but it passes
            // through the same cleaning as anything received: pasted content
            // brings whatever the page it came from had in it.
            $html = (new Sanitizer(true, null, true, false))->clean($html)['html'];
            $text = Text::fromHtml($html);
            $html = '<!doctype html><html><head><meta charset="utf-8"></head><body>'.$html.'</body></html>';
        } else {
            $text = (string) ($input['text'] ?? '');
        }

        $builder = new Builder($this->sender(), $to, $cc, $bcc, mb_substr($subject, 0, 500), $text, $html);

        foreach (['inReplyTo' => 'In-Reply-To', 'references' => 'References'] as $key => $name) {
            $value = $input[$key] ?? null;

            if (is_string($value) && preg_match('/^(\s*<[^<>\s]+>)+\s*$/', $value) === 1) {
                $builder->header($name, $value);
            }
        }

        $uploads = [];
        $total = 0;

        foreach (is_array($input['attachments'] ?? null) ? $input['attachments'] : [] as $id) {
            $attachment = $this->attachment((string) $id);

            if ($attachment === null) {
                if ($sending) {
                    throw new UserError('An attachment is no longer available. Remove it and add it again.');
                }

                continue;
            }

            $total += strlen($attachment['data']);

            if ($total > $this->config->int('limits.attachments')) {
                throw new UserError('The attachments are larger than the :size allowed.', 413, ['size' => self::bytes($this->config->int('limits.attachments'))]);
            }

            $builder->attach($attachment['name'], $attachment['type'], $attachment['data']);

            if (str_starts_with((string) $id, 'u:')) {
                $uploads[] = substr((string) $id, 2);
            }
        }

        return ['builder' => $builder, 'to' => $to, 'cc' => $cc, 'bcc' => $bcc, 'uploads' => $uploads];
    }

    private function submit(Builder $builder): void
    {
        $options = Mailbox::serverOptions($this->config, 'smtp');
        $hostname = (string) ($_SERVER['SERVER_NAME'] ?? gethostname() ?: 'localhost');

        try {
            $smtp = Smtp::connect($options, preg_replace('/[^A-Za-z0-9.-]/', '', $hostname) ?: 'localhost');
        } catch (RuntimeException $e) {
            error_log('mail-simply: '.$e->getMessage());

            throw new UserError('The mail server cannot be reached right now. Your message was not sent.', 503);
        }

        try {
            if ($this->config->bool('smtp.auth')) {
                try {
                    $this->grant['master']
                        ? $smtp->authenticate($this->config->string('master.user'), $this->config->string('master.password'), $this->mailbox->address)
                        : $smtp->authenticate($this->mailbox->address, (string) $this->grant['password']);
                } catch (Rejected $e) {
                    error_log('mail-simply: SMTP authentication failed: '.$e->reply);

                    throw new UserError('The mail server did not accept this sign-in. Your message was not sent.', 502);
                }
            }

            $smtp->send(Address::asciiDomain($this->mailbox->address), $builder->recipients(), $builder->build(false));
        } catch (Rejected $e) {
            throw new UserError(
                $e->recipient !== null ? 'The mail server refused :address: :reason' : 'The mail server refused the message: :reason',
                422,
                ['address' => (string) $e->recipient, 'reason' => $e->reply],
            );
        } finally {
            $smtp->quit();
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function afterSending(array $input): void
    {
        $draft = $input['draft'] ?? null;

        if (is_array($draft)) {
            $this->discardPrevious($draft, null, null);
        }

        $source = $input['source'] ?? null;

        if (is_array($source) && is_string($source['folder'] ?? null) && is_int($source['uid'] ?? null)) {
            try {
                $this->mailbox->flag($source['folder'], [$source['uid']], ($source['mode'] ?? '') === 'forward' ? 'forwarded' : 'answered', true);
            } catch (Throwable) {
                // The original may be gone by now; nothing to mark.
            }
        }
    }

    /**
     * Delete a saved draft the user has thrown away.
     */
    public function discardDraft(mixed $draft): void
    {
        $this->discardPrevious($draft, null, null);
    }

    private function discardPrevious(mixed $draft, ?string $keepFolder, ?int $keepUid): void
    {
        if (! is_array($draft) || ! is_string($draft['folder'] ?? null) || ! is_int($draft['uid'] ?? null)) {
            return;
        }

        if ($draft['folder'] === $keepFolder && $draft['uid'] === $keepUid) {
            return;
        }

        try {
            // Only ever a draft: a stale id must not delete a real message.
            $message = $this->mailbox->message($draft['folder'], $draft['uid'], false);

            if ($message['flags']['draft'] || $this->mailbox->role($draft['folder']) === 'drafts') {
                $this->mailbox->discard($draft['folder'], $draft['uid']);
            }
        } catch (UserError|CommandFailed) {
            // Already gone.
        }
    }

    /**
     * @param  array<string, list<string>>  $headers
     * @return array<string, mixed>
     */
    private function reopen(string $folder, int $uid, Part $structure, array $headers): array
    {
        $layout = new Layout($structure);

        // Images embedded in the draft come back as data: URLs, which is
        // how the editor holds them until the next save turns them into
        // parts again. The sanitizer marks where they go; they are fetched
        // once it is done.
        $body = $this->mailbox->body($folder, $uid, true, static fn (string $section): string => 'cid-section:'.$section, true, false);
        $html = (string) preg_replace_callback('/cid-section:([0-9.]+)/', function (array $match) use ($folder, $uid): string {
            try {
                $part = $this->mailbox->partContents($folder, $uid, $match[1]);
            } catch (Throwable) {
                return '';
            }

            return strlen($part['data']) > self::EMBED_LIMIT ? '' : 'data:'.$part['type'].';base64,'.base64_encode($part['data']);
        }, $body['html']);

        $header = static fn (string $name): ?string => $headers[$name][0] ?? null;

        return [
            'to' => Address::parseList(implode(', ', $headers['to'] ?? [])),
            'cc' => Address::parseList(implode(', ', $headers['cc'] ?? [])),
            'bcc' => Address::parseList(implode(', ', $headers['bcc'] ?? [])),
            'subject' => Header::decode($header('subject')),
            'html' => $body['plain'] ? null : $html,
            'text' => $body['plain'] ? $this->mailbox->quotable($folder, $uid)['text'] : null,
            'inReplyTo' => $header('in-reply-to'),
            'references' => $header('references'),
            'attachments' => $this->sources($folder, $uid, $layout->attachments),
            'source' => null,
            'draft' => ['folder' => $folder, 'uid' => $uid],
        ];
    }

    /**
     * @param  list<Part>  $parts
     * @return list<array{id: string, name: string, type: string, size: int}>
     */
    private function sources(string $folder, int $uid, array $parts): array
    {
        return array_map(static fn (Part $part): array => [
            'id' => 's:'.rtrim(strtr(base64_encode((string) json_encode([$folder, $uid, $part->section])), '+/', '-_'), '='),
            'name' => Mailbox::partName($part),
            'type' => $part->isAttachedMessage() ? 'message/rfc822' : $part->mimeType(),
            'size' => $part->encoding === 'base64' ? (int) floor($part->size * 3 / 4) : $part->size,
        ], $parts);
    }

    /**
     * @return array{name: string, type: string, data: string}|null
     */
    private function attachment(string $id): ?array
    {
        if (str_starts_with($id, 'u:')) {
            $upload = $this->uploads->find(substr($id, 2));

            return $upload === null ? null : ['name' => $upload['name'], 'type' => $upload['type'], 'data' => (string) file_get_contents($upload['path'])];
        }

        if (str_starts_with($id, 's:')) {
            $decoded = json_decode((string) base64_decode(strtr(substr($id, 2), '-_', '+/')), true);

            if (! is_array($decoded) || ! is_string($decoded[0] ?? null) || ! is_int($decoded[1] ?? null) || ! is_string($decoded[2] ?? null) || preg_match('/^[0-9.]+$/', $decoded[2]) !== 1) {
                return null;
            }

            try {
                return $this->mailbox->partContents($decoded[0], $decoded[1], $decoded[2]);
            } catch (UserError) {
                return null;
            }
        }

        return null;
    }

    /**
     * @return list<array{name: string, email: string}>
     */
    private function recipients(mixed $input): array
    {
        if (is_string($input)) {
            $input = Address::parseList($input);
        }

        if (! is_array($input)) {
            return [];
        }

        $addresses = [];

        foreach ($input as $entry) {
            $email = trim((string) (is_array($entry) ? ($entry['email'] ?? '') : $entry));
            $name = is_array($entry) ? trim((string) ($entry['name'] ?? '')) : '';

            if (! is_array($entry)) {
                $parsed = Address::parseOne($email);
                $email = $parsed['email'] ?? $email;
                $name = $parsed['name'] ?? '';
            }

            if (! Address::isValid($email)) {
                throw new UserError('":address" is not a valid address.', 422, ['address' => mb_substr($email, 0, 100)]);
            }

            $addresses[strtolower($email)] = ['name' => mb_substr($name, 0, 120), 'email' => $email];
        }

        return array_values($addresses);
    }

    private function formatDate(?string $iso, string $timezone): string
    {
        if ($iso === null) {
            return '';
        }

        try {
            $zone = new DateTimeZone($timezone !== '' ? $timezone : date_default_timezone_get());
        } catch (Throwable) {
            $zone = new DateTimeZone('UTC');
        }

        $date = (new DateTimeImmutable($iso))->setTimezone($zone);

        if (class_exists(\IntlDateFormatter::class)) {
            $formatter = new \IntlDateFormatter($this->lang->code, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::SHORT, $zone);
            $formatted = $formatter->format($date);

            if (is_string($formatted)) {
                return $formatted;
            }
        }

        return $date->format('Y-m-d H:i');
    }

    private static function prefixed(string $subject, string $prefix, string $pattern): string
    {
        return preg_match($pattern, $subject) === 1 ? $subject : trim($prefix.' '.$subject);
    }

    public static function bytes(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576).' MB' : max(1, (int) round($bytes / 1024)).' KB';
    }
}
