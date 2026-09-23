<?php

declare(strict_types=1);

namespace MailSimply\Mime;

/**
 * Decides what of a message is its body and what is attached to it.
 *
 * - multipart/alternative shows one of its versions: the HTML one where
 *   there is one (or the plain one when that is asked for);
 * - multipart/related shows its root part, and its other parts are the
 *   images that part refers to by Content-ID;
 * - multipart/signed shows what was signed, and keeps the signature out of
 *   the attachment list;
 * - any other multipart shows each of its text parts in turn, and offers the
 *   rest as attachments.
 */
final class Layout
{
    private const array SIGNATURES = ['application/pgp-signature', 'application/pkcs7-signature', 'application/x-pkcs7-signature'];

    /** @var list<Part> */
    public array $body = [];

    /** @var list<Part> */
    public array $attachments = [];

    /** @var array<string, Part> Parts referenced by Content-ID */
    public array $inline = [];

    public function __construct(Part $root, private readonly bool $preferHtml = true)
    {
        $this->walk($root, false);
    }

    /**
     * Whether any part is offered as an attachment.
     */
    public static function hasAttachments(Part $root): bool
    {
        return (new self($root))->attachments !== [];
    }

    private function walk(Part $part, bool $related): void
    {
        if ($part->isMultipart()) {
            match ($part->subtype) {
                'alternative' => $this->walk($this->chooseAlternative($part->parts), $related),
                'related' => $this->related($part),
                'signed' => array_map(fn (Part $child) => $this->walk($child, $related), $part->parts),
                default => array_map(fn (Part $child) => $this->walk($child, $related), $part->parts),
            };

            return;
        }

        if (in_array($part->mimeType(), self::SIGNATURES, true)) {
            return;
        }

        if ($part->id !== null) {
            $this->inline[$part->id] = $part;
        }

        if ($part->isBodyText()) {
            $this->body[] = $part;

            return;
        }

        // A related image is part of the body, not an attachment of its own.
        if ($related && $part->id !== null && $part->type === 'image') {
            return;
        }

        $this->attachments[] = $part;
    }

    private function related(Part $part): void
    {
        $start = isset($part->parameters['start']) ? trim($part->parameters['start'], '<> ') : null;
        $root = null;

        foreach ($part->parts as $child) {
            if ($start !== null && $child->id === $start) {
                $root = $child;
            }
        }

        $root ??= $part->parts[0] ?? null;

        foreach ($part->parts as $child) {
            $this->walk($child, $child !== $root);
        }
    }

    /**
     * @param  list<Part>  $parts
     */
    private function chooseAlternative(array $parts): Part
    {
        $html = null;
        $plain = null;

        foreach ($parts as $part) {
            $kind = $this->kind($part);

            if ($kind === 'html') {
                $html = $part;
            } elseif ($kind === 'plain' && $plain === null) {
                $plain = $part;
            }
        }

        $chosen = $this->preferHtml ? ($html ?? $plain) : ($plain ?? $html);

        return $chosen ?? $parts[count($parts) - 1];
    }

    private function kind(Part $part): ?string
    {
        if (! $part->isMultipart()) {
            return $part->type === 'text' && in_array($part->subtype, ['plain', 'html'], true) ? $part->subtype : null;
        }

        foreach ($part->leaves() as $leaf) {
            if ($leaf->mimeType() === 'text/html') {
                return 'html';
            }
        }

        foreach ($part->leaves() as $leaf) {
            if ($leaf->mimeType() === 'text/plain') {
                return 'plain';
            }
        }

        return null;
    }
}
