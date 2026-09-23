<?php

declare(strict_types=1);

namespace MailSimply\Mime;

/**
 * Converts text in whatever charset a message declares into UTF-8.
 *
 * Mail labels its text generously wrong, so a label is a hint: ISO-8859-1 is
 * read as its Windows-1252 superset (as every mail client does), an unknown or
 * missing label falls back to UTF-8 when the bytes are valid UTF-8 and to
 * Windows-1252 when they are not, and whatever cannot be converted is replaced
 * rather than failing the message. The result is always valid UTF-8.
 */
final class Charset
{
    /**
     * Labels mail uses that neither mbstring nor iconv know by that name.
     */
    private const array ALIASES = [
        'utf8' => 'UTF-8',
        'utf-8' => 'UTF-8',
        'us-ascii' => 'UTF-8',
        'ascii' => 'UTF-8',
        'iso-8859-1' => 'Windows-1252',
        'latin1' => 'Windows-1252',
        'iso_8859-1' => 'Windows-1252',
        'iso8859-1' => 'Windows-1252',
        'iso8859-2' => 'ISO-8859-2',
        'iso_8859-2' => 'ISO-8859-2',
        'latin2' => 'ISO-8859-2',
        'cp1250' => 'Windows-1250',
        'win-1250' => 'Windows-1250',
        'x-cp1250' => 'Windows-1250',
        'ks_c_5601-1987' => 'CP949',
        'gb2312' => 'GBK',
        'x-gbk' => 'GBK',
        'x-sjis' => 'SJIS',
        'shift-jis' => 'SJIS',
        'x-mac-roman' => 'MACINTOSH',
        'macintosh' => 'MACINTOSH',
        'unicode-1-1-utf-7' => 'UTF-7',
    ];

    /**
     * What mbstring lists beside the charsets: transfer encodings and
     * internal pseudo-encodings.
     */
    private const array NOT_CHARSETS = ['BASE64', 'UUENCODE', 'HTML-ENTITIES', 'Quoted-Printable', '7bit', '8bit', 'pass', 'auto', 'wchar'];

    public static function toUtf8(string $text, ?string $charset): string
    {
        if ($text === '') {
            return '';
        }

        $label = strtolower(trim((string) $charset, " \t\"'"));
        $target = self::ALIASES[$label] ?? ($label !== '' ? $label : null);

        if ($target === null || in_array($label, ['unknown-8bit', 'x-unknown', 'default', '8bit'], true)) {
            return mb_check_encoding($text, 'UTF-8') ? $text : self::fromWindows1252($text);
        }

        if (strcasecmp($target, 'UTF-8') === 0) {
            return mb_check_encoding($text, 'UTF-8') ? $text : mb_scrub($text, 'UTF-8');
        }

        $converted = self::convert($text, $target);

        return $converted ?? (mb_check_encoding($text, 'UTF-8') ? $text : self::fromWindows1252($text));
    }

    /**
     * Whether a charset label is one this can read.
     */
    public static function isKnown(string $charset): bool
    {
        $label = strtolower($charset);
        $target = self::ALIASES[$label] ?? $label;

        return self::mbstring($target) !== null || @iconv($target, 'UTF-8', 'a') !== false;
    }

    private static function convert(string $text, string $charset): ?string
    {
        $mb = self::mbstring($charset);

        if ($mb !== null) {
            $converted = @mb_convert_encoding($text, 'UTF-8', $mb);

            return is_string($converted) ? mb_scrub($converted, 'UTF-8') : null;
        }

        // iconv knows more charsets than mbstring (the Windows code pages
        // among them), but stops at the first byte it cannot map; //IGNORE
        // skips those instead, and still reports failure, hence the checks.
        $converted = @iconv($charset, 'UTF-8//IGNORE', $text);

        if (is_string($converted) && $converted !== '') {
            return mb_scrub($converted, 'UTF-8');
        }

        return null;
    }

    private static function fromWindows1252(string $text): string
    {
        return mb_scrub((string) mb_convert_encoding($text, 'UTF-8', 'Windows-1252'), 'UTF-8');
    }

    /**
     * mbstring's own name for a charset, when it has one.
     */
    private static function mbstring(string $charset): ?string
    {
        static $known = null;

        if ($known === null) {
            $known = [];

            foreach (mb_list_encodings() as $encoding) {
                // Not text charsets, and asking for their aliases is deprecated.
                if (in_array($encoding, self::NOT_CHARSETS, true)) {
                    continue;
                }

                $known[strtolower($encoding)] = $encoding;

                foreach (mb_encoding_aliases($encoding) as $alias) {
                    $known[strtolower($alias)] = $encoding;
                }
            }
        }

        return $known[strtolower($charset)] ?? null;
    }
}
