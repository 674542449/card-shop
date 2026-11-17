{{--
    <head> 里与设计无关的那一半：字符集、SEO、Open Graph、结构化数据、favicon。

    抽出来共享，是因为这些是业务而不是外观。每套模板都抄一份的话，一次 SEO 修正
    要在每套模板里各改一遍，而漏掉哪一套没有任何提示——这个文件里的 yieldContent
    双重转义那个坑就是真实修过的，不该有第二份能重新长出来。

    模板自己负责的部分不在这里：样式表、字体、以及 body 里的一切。
--}}
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- yieldContent() inside {{ }} instead of @yield, because @yield prints the
         section RAW. These sections carry product names and SEO text typed by the
         operator; a single " in a name closes the content attribute and everything
         after it becomes markup in <head>. @yield stays below for the sections that
         are meant to be HTML (structured_data, head, content, scripts). --}}
    {{-- Every view sets these with the inline form, @section('title', $value), and
         Blade escapes that value when it stores it (ManagesLayouts::startSection,
         `e($content)`). Printing it through {{ }} escaped it a second time, so a product
         named "A & B" reached search results as "A &amp; B". $seo() decodes the stored
         value once and {{ }} escapes it exactly once, which keeps the attribute safe.
         Fallbacks are applied with ?: rather than passed to yieldContent(), which
         escapes its $default as well. --}}
    @php
        $seo = fn (string $name): string => html_entity_decode($__env->yieldContent($name), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $metaTitle = $seo('title') ?: setting('seo_default_title') ?: setting('site_name', 'CardShop');
        // site_description is the last fallback so a shop that never filled in the SEO
        // settings still gives search engines a description instead of content="".
        $metaDescription = $seo('meta_description')
            ?: setting('seo_default_description')
            ?: setting('site_description', '');
        // A stored path or URL (product image, article cover), else the site logo.
        // Made absolute below because Open Graph consumers do not resolve relative URLs.
        $ogImageUrl = \App\Support\Seo::assetUrl($seo('og_image'))
            ?: \App\Support\Seo::assetUrl(setting('site_logo'));
    @endphp
    <title>{{ $metaTitle }}</title>
    <meta name="description" content="{{ $metaDescription }}">
    <meta name="keywords" content="{{ $seo('meta_keywords') ?: setting('seo_default_keywords', '') }}">

    {{-- Set by the noindex route middleware (order and payment pages) or search/empty
         pagination guards. When a page is marked noindex, canonical is omitted so
         search engines do not receive conflicting noindex + cross-page canonical signals. --}}
    @php
        $robotsDirective = \App\Http\Middleware\NoIndex::directiveFor(request());
        $canonicalUrl = !$robotsDirective ? $seo('canonical') : '';
    @endphp
    @if($robotsDirective)
    <meta name="robots" content="{{ $robotsDirective }}">
    @endif

    @if($canonicalUrl !== '')
    <link rel="canonical" href="{{ $canonicalUrl }}">
    @endif

    <meta property="og:type" content="{{ $seo('og_type') ?: 'website' }}">
    <meta property="og:locale" content="zh_CN">
    <meta property="og:title" content="{{ $metaTitle }}">
    <meta property="og:description" content="{{ $metaDescription }}">
    <meta property="og:url" content="{{ $canonicalUrl ?: \App\Support\Seo::url(request()->getPathInfo()) }}">
    <meta property="og:site_name" content="{{ setting('site_name', 'CardShop') }}">
    @if($ogImageUrl)
    <meta property="og:image" content="{{ $ogImageUrl }}">
    @endif

    <meta name="twitter:card" content="{{ $ogImageUrl ? 'summary_large_image' : 'summary' }}">
    <meta name="twitter:title" content="{{ $metaTitle }}">
    <meta name="twitter:description" content="{{ $metaDescription }}">
    @if($ogImageUrl)
    <meta name="twitter:image" content="{{ $ogImageUrl }}">
    @endif

    @yield('structured_data')

    @php $siteFavicon = \App\Support\SafeUrl::asset(setting('site_favicon')); @endphp
    @if($siteFavicon)
    {{-- Operator-supplied icon. type is omitted deliberately: the uploader accepts
         .ico and .png and the browser sniffs it correctly either way. --}}
    <link rel="icon" href="{{ $siteFavicon }}">
    <link rel="apple-touch-icon" href="{{ $siteFavicon }}">
    @endif
