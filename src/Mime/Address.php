<?php

declare(strict_types=1);

namespace MailSimply\Mime;

/**
 * Mail addresses: reading them out of headers and envelopes, checking the
 * ones a user types, and writing them into a message.
 */
final class Address
{
    /**
     * Parse an address list header, e.g. `"Doe, Jane" <jane@example.com>,
     * bob@example.com`, into name/email pairs. Groups contribute their
     * members; an empty group (`undisclosed-recipients:;`) contributes nothing.
     *
     * @return list<array{name: string, email: string}>
     */
    public static function parseList(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        $addresses = [];

        foreach (self::split($value) as $entry) {
            // A group's name ends at its colon; its members follow.
            if (preg_match('/^[^"<>@]*:(.*?);?$/s', $entry, $match) === 1) {
                array_push($addresses, ...self::parseList($match[1]));

                continue;
            }

            $address = self::parseOne($entry);

            if ($address !== null) {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }

    /**
     * @return array{name: string, email: string}|null
     */
    public static function parseOne(string $entry): ?array
    {
        $entry = trim($entry);

        if ($entry === '') {
            return null;
        }

        if (preg_match('/^(.*)<([^<>]*)>\s*(?:\(.*\))?$/s', $entry, $match) === 1) {
            $name = trim($match[1]);
            $email = trim($match[2]);
        } else {
            $comment = '';

            if (preg_match('/\(([^()]*)\)\s*$/', $entry, $parenthesised) === 1) {
                $comment = $parenthesised[1];
                $entry = trim(substr($entry, 0, -strlen($parenthesised[0])));
            }

            $name = $comment;
            $email = $entry;
        }

        if (strlen($name) >= 2 && $name[0] === '"' && str_ends_with($name, '"')) {
            $name = (string) preg_replace('/\\\\(.)/s', '$1', substr($name, 1, -1));
        }

        $email = trim($email, " \t\"'");

        if ($email === '') {
            return null;
        }

        return ['name' => trim(Header::decode($name)), 'email' => Header::decode($email)];
    }

    /**
     * Addresses from an ENVELOPE address list: (name adl mailbox host) each,
     * with group markers (a null host) skipped.
     *
     * @return list<array{name: string, email: string}>
     */
    public static function fromEnvelope(mixed $list): array
    {
        if (! is_array($list)) {
            return [];
        }

        $addresses = [];

        foreach ($list as $address) {
            if (! is_array($address) || ! isset($address[2]) || ($address[3] ?? null) === null) {
                continue;
            }

            $addresses[] = [
                'name' => trim(Header::decode(is_string($address[0] ?? null) ? $address[0] : '')),
                'email' => Header::decode($address[2].'@'.$address[3]),
            ];
        }

        return $addresses;
    }

    /**
     * Whether an address is one a message can be sent to. Deliberately not
     * RFC 5322 in full: quoted local parts and IP literals are refused,
     * because nobody types them and they only ever show up in abuse.
     */
    public static function isValid(string $email): bool
    {
        if (strlen($email) > 254 || preg_match('/[\s<>(),;:"\\\\\[\]]/', $email) === 1) {
            return false;
        }

        $at = strrpos($email, '@');

        if ($at === false || $at === 0 || $at === strlen($email) - 1 || $at > 64) {
            return false;
        }

        $domain = substr($email, $at + 1);
        $ascii = function_exists('idn_to_ascii') ? (idn_to_ascii($domain) ?: $domain) : $domain;

        return preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,62}$/i', $ascii) === 1;
    }

    /**
     * An address as it goes into a header.
     *
     * @param  array{name?: string, email: string}  $address
     */
    public static function format(array $address): string
    {
        $email = self::asciiDomain($address['email']);
        $name = trim($address['name'] ?? '');

        return $name === '' || $name === $address['email'] ? $email : Header::phrase($name).' <'.$email.'>';
    }

    /**
     * @param  list<array{name?: string, email: string}>  $addresses
     */
    public static function formatList(array $addresses): string
    {
        return implode(', ', array_map(self::format(...), $addresses));
    }

    /**
     * Addresses as a person reads them, e.g. in the header block of a
     * forwarded message: names as they are, nothing encoded.
     *
     * @param  list<array{name?: string, email: string}>  $addresses
     */
    public static function display(array $addresses): string
    {
        return implode(', ', array_map(
            static fn (array $address): string => trim($address['name'] ?? '') === '' ? $address['email'] : trim($address['name']).' <'.$address['email'].'>',
            $addresses,
        ));
    }

    /**
     * The address with an internationalised domain in its ASCII form, which
     * is how it travels unless the whole path speaks SMTPUTF8.
     */
    public static function asciiDomain(string $email): string
    {
        $at = strrpos($email, '@');

        if ($at === false || ! function_exists('idn_to_ascii') || preg_match('/[^\x20-\x7e]/', substr($email, $at + 1)) !== 1) {
            return $email;
        }

        $domain = idn_to_ascii(substr($email, $at + 1));

        return $domain === false ? $email : substr($email, 0, $at + 1).$domain;
    }

    /**
     * Split an address list at the commas that separate entries, leaving the
     * ones inside quotes, angle brackets and comments alone.
     *
     * @return list<string>
     */
    private static function split(string $value): array
    {
        $entries = [];
        $current = '';
        $quoted = false;
        $angle = false;
        $comment = 0;
        $group = false;

        for ($i = 0, $length = strlen($value); $i < $length; $i++) {
            $char = $value[$i];

            if ($quoted) {
                $current .= $char;

                if ($char === '\\' && $i + 1 < $length) {
                    $current .= $value[++$i];
                } elseif ($char === '"') {
                    $quoted = false;
                }

                continue;
            }

            match (true) {
                $char === '"' => $quoted = true,
                $char === '<' => $angle = true,
                $char === '>' => $angle = false,
                $char === '(' => $comment++,
                $char === ')' => $comment = max(0, $comment - 1),
                $char === ':' && ! $angle && $comment === 0 => $group = true,
                $char === ';' && ! $angle && $comment === 0 => $group = false,
                default => null,
            };

            // Within a group the commas separate members, so the group is
            // kept whole here and split again once its name is off.
            if (($char === ',' || ($char === ';' && ! $group)) && ! $angle && $comment === 0 && ! $group) {
                if ($char === ';') {
                    $current .= $char;
                }

                $entries[] = $current;
                $current = '';

                continue;
            }

            $current .= $char;
        }

        $entries[] = $current;

        return array_values(array_filter(array_map(trim(...), $entries), static fn (string $entry): bool => $entry !== ''));
    }
}
