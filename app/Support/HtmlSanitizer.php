<?php

namespace App\Support;

/**
 * Allowlist sanitiser for instructor-authored lesson bodies.
 *
 * Lesson content is rendered with `dangerouslySetInnerHTML` (Learn.jsx), so an
 * instructor — a lower-privileged role than admin — could otherwise persist
 * script that executes in every enrolled student's session and in admin
 * sessions. Sanitising on write makes the stored value safe to render.
 *
 * Everything is an allowlist: unknown elements, unknown attributes, `style`,
 * event handlers and non-http(s) URL schemes are dropped. Elements present in
 * the body (a document fragment) are unwrapped, so their text survives.
 */
final class HtmlSanitizer
{
    /**
     * @var array<string, list<string>>
     */
    private const ALLOWED = [
        'p' => ['class'],
        'br' => [],
        'span' => ['class'],
        'div' => ['class'],
        'strong' => [], 'b' => [],
        'em' => [], 'i' => [],
        'u' => [], 's' => [], 'sub' => ['class'], 'sup' => ['class'],
        'h1' => ['class'], 'h2' => ['class'], 'h3' => ['class'],
        'h4' => ['class'], 'h5' => ['class'], 'h6' => ['class'],
        'ul' => ['class'], 'ol' => ['class', 'start'], 'li' => ['class'],
        'dl' => ['class'], 'dt' => ['class'], 'dd' => ['class'],
        'blockquote' => ['class', 'cite'],
        'pre' => ['class'], 'code' => ['class'],
        'a' => ['class', 'href', 'title', 'target', 'rel'],
        'img' => ['class', 'src', 'alt', 'title', 'width', 'height', 'loading'],
        'table' => ['class'], 'thead' => ['class'], 'tbody' => ['class'],
        'tfoot' => ['class'], 'tr' => ['class'], 'th' => ['class', 'colspan', 'rowspan', 'scope'],
        'td' => ['class', 'colspan', 'rowspan'],
        'caption' => ['class'],
        'hr' => ['class'],
        'kbd' => ['class'], 'mark' => ['class'], 'small' => ['class'], 'sub' => ['class'], 'sup' => ['class'],
    ];

    /**
     * Elements removed together with their contents, because their content is
     * code/markup rather than prose.
     */
    private const DISCARD_WITH_CONTENT = ['script', 'style', 'noscript', 'template', 'iframe', 'object', 'embed', 'form', 'svg', 'math'];

    /**
     * URL-bearing attributes: the value is dropped unless the scheme is http(s),
     * mailto, or a site-relative path.
     */
    private const URL_ATTRIBUTES = ['href', 'src', 'cite'];

    private const ALLOWED_SCHEMES = ['http', 'https', 'mailto'];

    public static function clean(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        // Cheap reject: nothing here means there is nothing to parse.
        if (! str_contains($html, '<')) {
            return $html;
        }

        $document = new \DOMDocument('1.0', 'UTF-8');

        $previous = libxml_use_internal_errors(true);

        $loaded = $document->loadHTML(
            '<?xml encoding="utf-8"?><body>'.$html.'</body>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            // Unparseable markup is not worth guessing at; store it as text.
            return htmlspecialchars(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $body = $document->getElementsByTagName('body')->item(0);

        if (! $body instanceof \DOMElement) {
            return null;
        }

        self::cleanChildren($body);

        $output = '';

        foreach ($body->childNodes as $child) {
            $output .= $document->saveHTML($child);
        }

        return trim($output) === '' ? null : $output;
    }

    private static function cleanChildren(\DOMNode $parent): void
    {
        // Iterate over a snapshot: the live NodeList shifts as nodes are removed.
        $children = [];

        foreach ($parent->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if ($child instanceof \DOMText || $child instanceof \DOMCdataSection) {
                continue;
            }

            if (! $child instanceof \DOMElement) {
                $parent->removeChild($child);

                continue;
            }

            $tag = strtolower($child->nodeName);

            if (in_array($tag, self::DISCARD_WITH_CONTENT, true)) {
                $parent->removeChild($child);

                continue;
            }

            if (! array_key_exists($tag, self::ALLOWED)) {
                // Unknown element: keep the text, drop the tag.
                self::cleanChildren($child);

                while ($child->firstChild !== null) {
                    $parent->insertBefore($child->firstChild, $child);
                }

                $parent->removeChild($child);

                continue;
            }

            self::cleanAttributes($child, $tag);

            self::cleanChildren($child);
        }
    }

    /**
     * @param  list<string>  $allowedAttributes
     */
    private static function cleanAttributes(\DOMElement $element, string $tag): void
    {
        $allowed = self::ALLOWED[$tag];

        $attributes = [];

        foreach ($element->attributes as $attribute) {
            $attributes[] = $attribute->nodeName;
        }

        foreach ($attributes as $name) {
            $lowered = strtolower($name);

            if (! in_array($lowered, $allowed, true)) {
                $element->removeAttribute($name);

                continue;
            }

            if (! in_array($lowered, self::URL_ATTRIBUTES, true)) {
                continue;
            }

            if (! self::isSafeUrl($element->getAttribute($name))) {
                $element->removeAttribute($name);
            }
        }

        // A link that opens a new tab must not hand the opener to the target.
        if ($tag === 'a' && $element->getAttribute('target') !== '') {
            $element->setAttribute('rel', 'noopener noreferrer');
        }

        if ($tag === 'img') {
            $element->setAttribute('loading', 'lazy');
        }
    }

    private static function isSafeUrl(string $url): bool
    {
        $trimmed = trim($url);

        if ($trimmed === '') {
            return false;
        }

        // Protocol-relative and site-relative URLs cannot carry a scheme.
        if (str_starts_with($trimmed, '/') || str_starts_with($trimmed, '#')) {
            return true;
        }

        $scheme = strtolower((string) parse_url($trimmed, PHP_URL_SCHEME));

        if ($scheme === '') {
            // No scheme and not relative — e.g. "example.com/page". Treat as relative.
            return ! preg_match('/^[a-z][a-z0-9+.\-]*:/i', $trimmed);
        }

        return in_array($scheme, self::ALLOWED_SCHEMES, true);
    }
}
