<?php

declare(strict_types=1);

namespace MailSimply\Html;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;

/**
 * Conversions between plain text and HTML: the text alternative of an HTML
 * message, and the HTML a plain-text message is shown and quoted as.
 */
final class Text
{
    private const array BLOCKS = [
        'address', 'article', 'aside', 'blockquote', 'center', 'dd', 'details', 'div', 'dl', 'dt', 'figcaption',
        'figure', 'footer', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hr', 'li', 'main', 'nav', 'ol', 'p', 'pre',
        'section', 'summary', 'table', 'tr', 'ul',
    ];

    /**
     * Render HTML as readable plain text: paragraphs apart, list items
     * bulleted, quotes prefixed with "> ", and links followed by where they go.
     */
    public static function fromHtml(string $html): string
    {
        $document = HTMLDocument::createFromString($html === '' ? '<p></p>' : $html, LIBXML_NOERROR | LIBXML_COMPACT, 'UTF-8');
        $text = $document->body === null ? '' : self::walk($document->body, false);

        // Collapse the blank lines block boundaries leave behind.
        $text = (string) preg_replace("/[ \t]+\n/", "\n", $text);
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text, "\n");
    }

    /**
     * Render plain text as HTML: escaped, links made clickable, quoted lines
     * set as quotes. With format=flowed, soft line breaks are joined again.
     */
    public static function toHtml(string $text, bool $flowed = false, bool $delsp = false): string
    {
        $text = (string) preg_replace('/\r\n?/', "\n", $text);

        if ($flowed) {
            $text = self::unflow($text, $delsp);
        }

        $html = '';
        $depth = 0;

        foreach (explode("\n", $text) as $line) {
            preg_match('/^((?:>\s?)*)/', $line, $match);
            $level = substr_count($match[1], '>');
            $content = substr($line, strlen($match[1]));

            while ($depth < $level) {
                $html .= '<blockquote>';
                $depth++;
            }

            while ($depth > $level) {
                $html = rtrim($html, "\n").'</blockquote>';
                $depth--;
            }

            $html .= self::linkify(htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))."\n";
        }

        $html = rtrim($html, "\n").str_repeat('</blockquote>', $depth);

        return '<div class="plain">'.$html.'</div>';
    }

    /**
     * Turn URLs and addresses in escaped text into links.
     */
    public static function linkify(string $escaped): string
    {
        return (string) preg_replace_callback(
            // The text is escaped already, so a quote or bracket around a URL
            // arrives as an entity, which must end the URL like the character.
            '~\b((?:https?://|www\.)(?:(?!&quot;|&#0?39;|&lt;|&gt;)[^\s<>"\'])*(?:(?!&quot;|&#0?39;|&lt;|&gt;)[^\s<>"\'.,;:!?)\]}]))|\b([a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,})\b~i',
            static function (array $match): string {
                if (($match[2] ?? '') !== '') {
                    return '<a href="mailto:'.$match[2].'">'.$match[2].'</a>';
                }

                $url = $match[1];
                $href = str_starts_with(strtolower($url), 'www.') ? 'https://'.$url : $url;

                return '<a href="'.$href.'" target="_blank" rel="noopener noreferrer">'.$url.'</a>';
            },
            $escaped,
        );
    }

    /**
     * Undo format=flowed (RFC 3676): a line ending in a space continues on
     * the next one at the same quote depth. The signature separator is the
     * one line that ends in a space and still stands alone.
     */
    private static function unflow(string $text, bool $delsp): string
    {
        $lines = explode("\n", $text);
        $output = [];
        $current = null;
        $currentDepth = 0;

        foreach ($lines as $line) {
            preg_match('/^(>*)/', $line, $match);
            $depth = strlen($match[1]);
            $content = substr($line, $depth);

            // Space-stuffing.
            if (str_starts_with($content, ' ')) {
                $content = substr($content, 1);
            }

            if ($current !== null && $depth === $currentDepth) {
                $current .= $content;
            } else {
                if ($current !== null) {
                    $output[] = str_repeat('>', $currentDepth).($currentDepth > 0 ? ' ' : '').$current;
                }

                $current = $content;
                $currentDepth = $depth;
            }

            $soft = str_ends_with($content, ' ') && $content !== '-- ';

            if (! $soft) {
                $output[] = str_repeat('>', $currentDepth).($currentDepth > 0 ? ' ' : '').$current;
                $current = null;
            } elseif ($delsp) {
                $current = substr($current, 0, -1);
            }
        }

        if ($current !== null) {
            $output[] = str_repeat('>', $currentDepth).($currentDepth > 0 ? ' ' : '').$current;
        }

        return implode("\n", $output);
    }

    private static function walk(Node $node, bool $pre): string
    {
        $text = '';

        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $content = (string) $child->textContent;
                $text .= $pre ? $content : (string) preg_replace('/\s+/u', ' ', $content);

                continue;
            }

            if (! $child instanceof Element) {
                continue;
            }

            $name = strtolower($child->localName);

            if (in_array($name, ['script', 'style', 'head', 'title', 'template'], true)) {
                continue;
            }

            $inner = self::walk($child, $pre || $name === 'pre');

            $text .= match ($name) {
                'br' => "\n",
                'hr' => "\n".str_repeat('-', 40)."\n",
                'li' => "\n- ".trim($inner),
                'td', 'th' => $inner."\t",
                'img' => ($alt = trim((string) $child->getAttribute('alt'))) !== '' ? '['.$alt.']' : '',
                'a' => self::link($child, $inner),
                'blockquote' => "\n".self::quote(trim($inner))."\n",
                default => in_array($name, self::BLOCKS, true) ? "\n".$inner."\n" : $inner,
            };
        }

        return $text;
    }

    private static function link(Element $element, string $inner): string
    {
        $href = trim((string) $element->getAttribute('href'));
        $label = trim($inner);

        if ($href === '' || str_starts_with($href, '#') || $label === '' || str_contains($label, preg_replace('#^(https?://|mailto:)#i', '', $href) ?? $href)) {
            return $inner;
        }

        return $inner.' <'.$href.'>';
    }

    private static function quote(string $text): string
    {
        return implode("\n", array_map(
            static fn (string $line): string => $line === '' || str_starts_with($line, '>') ? '>'.$line : '> '.$line,
            explode("\n", $text),
        ));
    }
}
