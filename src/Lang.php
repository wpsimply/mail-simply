<?php

declare(strict_types=1);

namespace MailSimply;

/**
 * Interface translations.
 *
 * Strings are written in English in the code and looked up by that English
 * text in lang/<code>.php, the way Laravel's JSON translations work: a string
 * with no translation shows in English rather than as a key. Placeholders are
 * written :name and filled in after translation.
 */
final class Lang
{
    /**
     * Every language the interface speaks, by its own name.
     */
    public const array AVAILABLE = [
        'en' => 'English',
        'hu' => 'Magyar',
    ];

    /** @var array<string, string> */
    private array $lines;

    public function __construct(public readonly string $code)
    {
        $file = dirname(__DIR__).'/lang/'.$code.'.php';
        $lines = $code !== 'en' && is_file($file) ? require $file : [];
        $this->lines = is_array($lines) ? $lines : [];
    }

    public static function isAvailable(string $code): bool
    {
        return array_key_exists($code, self::AVAILABLE);
    }

    /**
     * The language to speak: the mailbox's own choice, then the browser's,
     * then the configured default.
     */
    public static function negotiate(?string $preferred, ?string $acceptLanguage, string $default): string
    {
        if ($preferred !== null && self::isAvailable($preferred)) {
            return $preferred;
        }

        $ranked = [];

        foreach (explode(',', (string) $acceptLanguage) as $index => $range) {
            if (preg_match('/^\s*([a-z]{1,8})(?:-[a-z0-9]{1,8})*\s*(?:;\s*q=([0-9.]+))?/i', $range, $match) === 1) {
                $ranked[] = [strtolower($match[1]), isset($match[2]) ? (float) $match[2] : 1.0, $index];
            }
        }

        usort($ranked, static fn (array $a, array $b): int => [$b[1], $a[2]] <=> [$a[1], $b[2]]);

        foreach ($ranked as [$code, $quality]) {
            if ($quality > 0 && self::isAvailable($code)) {
                return $code;
            }
        }

        return self::isAvailable($default) ? $default : 'en';
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    public function get(string $text, array $replace = []): string
    {
        $line = $this->lines[$text] ?? $text;

        foreach ($replace as $name => $value) {
            $line = str_replace(':'.$name, (string) $value, $line);
        }

        return $line;
    }

    /**
     * Every translation, for the browser.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->lines;
    }
}
