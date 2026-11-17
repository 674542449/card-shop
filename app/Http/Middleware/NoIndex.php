<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps order and payment pages out of search results.
 *
 * These pages are reachable by crawlers (the order lookup is linked from every
 * page) but carry nothing worth indexing, and a detail page can carry a buyer's
 * purchased cards. They are deliberately NOT disallowed in robots.txt: a crawler
 * that may not fetch a URL never sees its noindex, and can still list the bare URL
 * when something links to it.
 *
 * Both signals are sent. X-Robots-Tag covers every response type (the card
 * download is plain text); the request attribute makes shared/head.blade.php print
 * the equivalent <meta name="robots">, which crawlers that ignore the header —
 * Baidu documents only the meta tag — still honour.
 */
class NoIndex
{
    public const ATTRIBUTE = 'seo_noindex';

    public const DIRECTIVE = 'noindex, nofollow, noarchive';

    public const SEARCH_DIRECTIVE = 'noindex, follow';

    public static function mark(Request $request, string $directive = self::DIRECTIVE): void
    {
        $request->attributes->set(self::ATTRIBUTE, $directive);
    }

    public static function directiveFor(Request $request): ?string
    {
        $value = $request->attributes->get(self::ATTRIBUTE);
        if ($value === true) {
            return self::DIRECTIVE;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function handle(Request $request, Closure $next): Response
    {
        self::mark($request, self::DIRECTIVE);

        $response = $next($request);
        $response->headers->set('X-Robots-Tag', self::DIRECTIVE);

        return $response;
    }
}
