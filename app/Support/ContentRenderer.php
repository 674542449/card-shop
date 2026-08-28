<?php

namespace App\Support;

use HTMLPurifier;
use HTMLPurifier_Config;
use League\CommonMark\CommonMarkConverter;

/**
 * Renders stored product descriptions and article bodies to display HTML.
 *
 * Rows written before the admin gained a WYSIWYG editor hold Markdown; rows written
 * after it hold HTML. Both live in the same column and there is no format flag, so the
 * format is detected per value and the two are never mixed within one document.
 */
class ContentRenderer
{
    /**
     * Tags that only ever appear in a real HTML document. Any one of them settles it.
     *
     * The tag name must be followed by `>`, `/>` or whitespace-then-attributes, so prose
     * that merely compares numbers ("if a < b"), a Markdown autolink (`<https://…>`) or a
     * stray bracket cannot be mistaken for markup.
     */
    private const HTML_BLOCK_PATTERN = '/<(?:p|div|h[1-6]|ul|ol|li|table|hr|blockquote|pre)(?:\s[^<>]*)?\/?>/i';

    /**
     * Tags an author plausibly hand-types inside Markdown. `<br>` is how most people
     * force a line break instead of Markdown's invisible two trailing spaces, and
     * `<img>` was the only way to embed an image before the editor existed. On their
     * own these prove nothing, so they only count as HTML when no Markdown block
     * syntax is present — otherwise a legacy description would render its `# 标题`
     * and `- 列表` as literal text.
     */
    private const HTML_WEAK_PATTERN = '/<(?:img|br)(?:\s[^<>]*)?\/?>/i';

    private const MARKDOWN_PATTERN = '/^(?:\s*(?:#{1,6}\s|[-*+]\s|\d+\.\s|>\s|```))/m';

    private const ALLOWED_HTML = 'p[style],br,hr,'
        . 'h1[style],h2[style],h3[style],h4[style],h5[style],h6[style],'
        . 'strong,b,em,i,u,s,strike,del,ins,sub,sup,'
        . 'span[style],'
        . 'ul,ol,li,'
        . 'blockquote,pre,code[class],'
        . 'table[style],thead,tbody,tfoot,tr,'
        . 'th[style|colspan|rowspan],td[style|colspan|rowspan],'
        . 'a[href|title|target|rel],'
        . 'img[src|alt|width|height|style]';

    /**
     * Editors express alignment, colour and indentation as inline styles. Declaring the
     * properties one by one means anything else — `expression()`, `behavior`, `position`,
     * a `url()` payload — is dropped by the CSS parser rather than filtered by pattern.
     */
    private const ALLOWED_CSS = 'text-align,text-indent,text-decoration,color,background-color,'
        . 'font-size,font-weight,font-style,font-family,line-height,'
        . 'width,height,max-width,margin-left,padding-left';

    private static ?HTMLPurifier $purifier = null;

    public static function toHtml(?string $raw): string
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return '';
        }

        return self::looksLikeHtml($raw)
            ? self::purifier()->purify($raw)
            : self::markdown($raw);
    }

    private static function looksLikeHtml(string $raw): bool
    {
        if (preg_match(self::HTML_BLOCK_PATTERN, $raw) === 1) {
            return true;
        }

        return preg_match(self::HTML_WEAK_PATTERN, $raw) === 1
            && preg_match(self::MARKDOWN_PATTERN, $raw) !== 1;
    }

    private static function markdown(string $raw): string
    {
        $converter = new CommonMarkConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        return $converter->convert($raw)->getContent();
    }

    private static function purifier(): HTMLPurifier
    {
        if (self::$purifier instanceof HTMLPurifier) {
            return self::$purifier;
        }

        $config = HTMLPurifier_Config::createDefault();

        $config->set('Core.Encoding', 'UTF-8');
        // Transitional rather than Strict: `target` on an anchor is not valid in Strict
        // and HTMLPurifier would drop it before the noopener transform ever runs.
        $config->set('HTML.Doctype', 'HTML 4.01 Transitional');
        $config->set('HTML.Allowed', self::ALLOWED_HTML);
        $config->set('CSS.AllowedProperties', self::ALLOWED_CSS);

        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        $config->set('Attr.AllowedRel', ['noopener', 'noreferrer', 'nofollow']);
        $config->set('HTML.TargetNoopener', true);
        $config->set('HTML.TargetNoreferrer', true);

        // `data:` is restricted by HTMLPurifier itself to base64 image payloads, which is
        // what an editor produces when an image is pasted rather than uploaded.
        $config->set('URI.AllowedSchemes', [
            'http' => true,
            'https' => true,
            'mailto' => true,
            'data' => true,
        ]);

        self::applyCachePath($config);

        return self::$purifier = new HTMLPurifier($config);
    }

    /**
     * HTMLPurifier serialises its compiled definitions to disk and throws on boot when the
     * directory is missing. Falling back to no cache keeps an unwritable storage/ from
     * turning every product page into a 500 — it only costs the compile on each request.
     */
    private static function applyCachePath(HTMLPurifier_Config $config): void
    {
        $path = storage_path('framework/cache/purifier');

        if (is_dir($path) || @mkdir($path, 0775, true) || is_dir($path)) {
            $config->set('Cache.SerializerPath', $path);
            return;
        }

        $config->set('Cache.DefinitionImpl', null);
    }
}
