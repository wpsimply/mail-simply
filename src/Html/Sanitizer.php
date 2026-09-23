<?php

declare(strict_types=1);

namespace MailSimply\Html;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;

/**
 * Makes message HTML safe to show, and quiet: no script, no forms, no frames,
 * and no request to anywhere else unless the reader has asked for it.
 *
 * The input is parsed the way a browser parses it (PHP's HTML5 parser), then
 * written out again from an allowlist: known elements and attributes only,
 * every text node escaped, URLs checked scheme by scheme, CSS stripped of
 * anything that loads or runs. Writing out a fresh copy, rather than deleting
 * what looks bad from the parsed tree, is what keeps parser differentials out:
 * nothing reaches the output that this class did not put there itself, and
 * there are no raw-text elements, SVG or MathML for a browser to re-read
 * differently.
 *
 * This is the first of two walls. The result is shown in a sandboxed frame
 * without scripts, under a Content-Security-Policy of its own (frame.php).
 */
final class Sanitizer
{
    /**
     * Elements kept, with their children.
     */
    private const array ELEMENTS = [
        'a', 'abbr', 'address', 'article', 'aside', 'b', 'bdi', 'bdo', 'big', 'blockquote', 'br', 'caption', 'center',
        'cite', 'code', 'col', 'colgroup', 'dd', 'del', 'details', 'dfn', 'div', 'dl', 'dt', 'em', 'figcaption', 'figure',
        'font', 'footer', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hr', 'i', 'img', 'ins', 'kbd', 'li', 'main',
        'mark', 'nav', 'ol', 'p', 'pre', 'q', 'rp', 'rt', 'ruby', 's', 'samp', 'section', 'small', 'span', 'strike',
        'strong', 'sub', 'summary', 'sup', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'time', 'tr', 'tt', 'u', 'ul',
        'var', 'wbr',
    ];

    /**
     * Elements dropped together with everything inside them. Anything not
     * listed here or above is unwrapped: it goes, its children stay.
     */
    private const array DROPPED = [
        'script', 'noscript', 'style', 'template', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'param',
        'form', 'input', 'button', 'select', 'option', 'optgroup', 'textarea', 'datalist', 'output', 'keygen', 'meter',
        'progress', 'svg', 'math', 'audio', 'video', 'source', 'track', 'canvas', 'map', 'area', 'link', 'meta', 'base',
        'title', 'head', 'dialog', 'portal', 'picture', 'xmp', 'plaintext', 'noembed', 'noframes', 'slot',
    ];

    private const array VOID = ['br', 'hr', 'img', 'col', 'wbr'];

    private const array ATTRIBUTES = [
        'align', 'alt', 'bgcolor', 'border', 'cellpadding', 'cellspacing', 'class', 'color', 'colspan', 'dir', 'face',
        'height', 'hspace', 'lang', 'rowspan', 'size', 'span', 'start', 'style', 'summary', 'title', 'valign', 'vspace',
        'width', 'datetime', 'abbr', 'headers', 'scope', 'nowrap', 'reversed', 'open',
    ];

    /**
     * Only <ol> and <li> take "type" (a numbering style) and "value".
     */
    private const array LIST_ATTRIBUTES = ['type', 'value'];

    private const string DATA_IMAGE = '#^data:image/(png|jpe?g|gif|webp|bmp);base64,[a-z0-9+/=\s]+$#i';

    /**
     * Remote resources left out because remote content is off.
     */
    private int $blocked = 0;

    /** @var list<string> */
    private array $styles = [];

    /**
     * @param  bool  $allowRemote  Load images and styles from the web.
     * @param  (callable(string): ?string)|null  $cid  Where a cid: reference points, or null to drop it.
     * @param  bool  $links  Keep links.
     * @param  bool  $stylesheets  Keep <style> elements. Off wherever the HTML lands in the
     *                             interface itself (the editor, a signature) rather than in a
     *                             frame of its own, where a stylesheet would restyle the page.
     */
    public function __construct(
        private readonly bool $allowRemote = false,
        private $cid = null,
        private readonly bool $links = true,
        private readonly bool $stylesheets = true,
    ) {}

    /**
     * @return array{html: string, blocked: int}
     */
    public function clean(string $html): array
    {
        $this->blocked = 0;
        $this->styles = [];

        if (trim($html) === '') {
            return ['html' => '', 'blocked' => 0];
        }

        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR | LIBXML_COMPACT, 'UTF-8');

        // Styles in <head> are how most newsletters are laid out.
        if ($document->head !== null) {
            foreach ($document->head->querySelectorAll('style') as $style) {
                $this->collectStyle($style->textContent ?? '');
            }
        }

        $body = $document->body;
        $content = $body === null ? '' : $this->children($body);

        // The body's own colours and background are kept on a wrapper, since
        // the body element itself is not part of what is written out.
        if ($body !== null) {
            $attributes = $this->attributes($body, 'div');

            if ($attributes !== '') {
                $content = '<div'.$attributes.'>'.$content.'</div>';
            }
        }

        $styles = $this->styles === [] ? '' : '<style>'.implode("\n", $this->styles).'</style>';

        return ['html' => $styles.$content, 'blocked' => $this->blocked];
    }

    private function children(Node $node): string
    {
        $html = '';

        foreach ($node->childNodes as $child) {
            $html .= $this->node($child);
        }

        return $html;
    }

    private function node(Node $node): string
    {
        if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
            return htmlspecialchars((string) $node->textContent, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        }

        if (! $node instanceof Element) {
            // Comments, processing instructions, doctypes.
            return '';
        }

        $name = strtolower($node->localName);

        if ($node->namespaceURI !== 'http://www.w3.org/1999/xhtml') {
            return '';
        }

        if ($name === 'style') {
            $this->collectStyle((string) $node->textContent);

            return '';
        }

        if (in_array($name, self::DROPPED, true)) {
            return '';
        }

        if (! in_array($name, self::ELEMENTS, true)) {
            return $this->children($node);
        }

        if ($name === 'a' && ! $this->links) {
            return $this->children($node);
        }

        if ($name === 'img') {
            $src = $this->image((string) $node->getAttribute('src'));

            if ($src === null) {
                $alt = trim((string) $node->getAttribute('alt'));

                return $alt === '' ? '' : htmlspecialchars('['.$alt.']', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }

            return '<img src="'.self::escape($src).'"'.$this->attributes($node, $name).'>';
        }

        $attributes = $this->attributes($node, $name);

        if ($name === 'a') {
            $href = $this->link((string) $node->getAttribute('href'));

            if ($href !== null) {
                $attributes = ' href="'.self::escape($href).'"'
                    .(str_starts_with($href, '#') ? '' : ' target="_blank" rel="noopener noreferrer"')
                    .$attributes;
            }
        }

        if (in_array($name, self::VOID, true)) {
            return '<'.$name.$attributes.'>';
        }

        return '<'.$name.$attributes.'>'.$this->children($node).'</'.$name.'>';
    }

    private function attributes(Element $element, string $name): string
    {
        $html = '';

        foreach ($element->attributes as $attribute) {
            $attribute_name = strtolower($attribute->localName);
            $value = (string) $attribute->value;

            if ($attribute_name === 'background' && in_array($name, ['table', 'td', 'th', 'tr', 'div'], true)) {
                $url = $this->image($value);

                if ($url !== null) {
                    $html .= ' background="'.self::escape($url).'"';
                }

                continue;
            }

            $allowed = in_array($attribute_name, self::ATTRIBUTES, true)
                || (in_array($attribute_name, self::LIST_ATTRIBUTES, true) && in_array($name, ['ol', 'li', 'ul'], true));

            if (! $allowed || ($name === 'div' && $element->localName === 'body' && ! in_array($attribute_name, ['bgcolor', 'style', 'background', 'color'], true))) {
                continue;
            }

            if ($attribute_name === 'style') {
                $value = $this->css($value);

                if (trim($value) === '') {
                    continue;
                }
            }

            $html .= ' '.$attribute_name.'="'.self::escape($value).'"';
        }

        return $html;
    }

    /**
     * Where a link may go: the web, mail, a phone number, or somewhere else
     * in the same message.
     */
    private function link(string $href): ?string
    {
        $href = trim(self::stripControl($href));

        if ($href === '') {
            return null;
        }

        if (str_starts_with($href, '#')) {
            return $href;
        }

        return preg_match('#^(https?:|mailto:|tel:)#i', $href) === 1 ? $href : null;
    }

    /**
     * Where an image may load from: the message itself (cid:), inline data,
     * or -- when remote content is on -- the web.
     */
    private function image(string $src): ?string
    {
        $src = trim(self::stripControl($src));

        if ($src === '') {
            return null;
        }

        if (stripos($src, 'cid:') === 0) {
            return $this->cid === null ? null : ($this->cid)(rawurldecode(substr($src, 4)));
        }

        if (preg_match(self::DATA_IMAGE, $src) === 1) {
            return $src;
        }

        if (preg_match('#^(https?:)?//#i', $src) === 1) {
            if ($this->allowRemote) {
                return str_starts_with($src, '//') ? 'https:'.$src : $src;
            }

            $this->blocked++;
        }

        return null;
    }

    private function collectStyle(string $css): void
    {
        if (! $this->stylesheets) {
            return;
        }

        $css = $this->css($css);

        if (trim($css) !== '') {
            // Nothing in a stylesheet needs "<"; without it, the style element
            // cannot be closed early from inside.
            $this->styles[] = str_replace('<', '\3C ', $css);
        }
    }

    /**
     * Clean CSS, from a style attribute or a style element: nothing that
     * runs script (expression(), behavior, -moz-binding), nothing that loads
     * from elsewhere unless remote content is on (url(), @import, @font-face).
     */
    private function css(string $css): string
    {
        $css = self::stripControl($css);

        // Escapes are how "expression" gets spelled "e\78pression"; a message
        // has no honest need for them.
        $css = (string) preg_replace('/\\\\[0-9a-f]{1,6}\s?|\\\\/i', '', $css);
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);

        if (preg_match('/expression\s*\(|behavior\s*:|-moz-binding|javascript:|vbscript:/i', $css) === 1) {
            return '';
        }

        $css = (string) preg_replace('/@(import|charset|namespace)\b[^;]*;?/i', '', $css);
        $css = (string) preg_replace('/@font-face\s*\{[^}]*\}/i', '', $css);

        // image-set() takes bare strings as URLs, out of reach of the url()
        // check below; mail has no use for it.
        $css = (string) preg_replace('/(-webkit-)?image-set\s*\((?:[^()]|\([^()]*\))*\)/i', 'none', $css);

        return (string) preg_replace_callback('/url\(\s*([\'"]?)(.*?)\1\s*\)/is', function (array $match): string {
            $url = $this->image($match[2]);

            return $url === null ? 'none' : 'url("'.str_replace(['"', "\n", "\r"], ['%22', '', ''], $url).'")';
        }, $css);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    private static function stripControl(string $value): string
    {
        return (string) preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $value);
    }
}
