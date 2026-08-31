{{--
    <head> 里与设计无关的那一半：字符集、SEO、Open Graph、结构化数据、favicon。

    抽出来共享，是因为这些是业务而不是外观。每套模板都抄一份的话，一次 SEO 修正
    要在每套模板里各改一遍，而漏掉哪一套没有任何提示——这个文件里的 yieldContent
    双重转义那个坑就是真实修过的，不该有第二份能重新长出来。

    模板自己负责的部分不在这里：样式表、字体、以及 body 里的一切。
--}}
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- yieldContent() inside {{ }} instead of @yield, because @yield prints the
         section RAW. These sections carry product names and SEO text typed by the
         operator; a single " in a name closes the content attribute and everything
         after it becomes markup in <head>. @yield stays below for the sections that
         are meant to be HTML (structured_data, head, content, scripts). --}}
    {{-- The fallback is applied with ?: rather than passed to yieldContent().
         yieldContent() escapes its $default itself (ManagesLayouts::yieldContent, the
         `e($default)` on the first line), so handing it the raw setting and wrapping
         the call in {{ }} escaped it twice — a shop whose SEO description contains
         "A & B" served "A &amp;amp; B" to search engines on every page with no
         section of its own. Defined sections come back unescaped and are escaped
         exactly once, here, which is the point of the {{ }}. --}}
    <title>{{ $__env->yieldContent('title') ?: setting('seo_default_title') ?: setting('site_name', 'CardShop') }}</title>
    <meta name="description" content="{{ $__env->yieldContent('meta_description') ?: setting('seo_default_description', '') }}">
    <meta name="keywords" content="{{ $__env->yieldContent('meta_keywords') ?: setting('seo_default_keywords', '') }}">

    @hasSection('canonical')
    <link rel="canonical" href="{{ $__env->yieldContent('canonical') }}">
    @endif

    <meta property="og:type" content="{{ $__env->yieldContent('og_type', 'website') }}">
    <meta property="og:title" content="{{ $__env->yieldContent('title') ?: setting('seo_default_title') ?: setting('site_name', 'CardShop') }}">
    <meta property="og:description" content="{{ $__env->yieldContent('meta_description') ?: setting('seo_default_description', '') }}">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:site_name" content="{{ setting('site_name', 'CardShop') }}">

    @yield('structured_data')

    @php $siteFavicon = setting('site_favicon'); @endphp
    @if($siteFavicon)
    {{-- Operator-supplied icon. type is omitted deliberately: the uploader accepts
         .ico and .png and the browser sniffs it correctly either way. --}}
    <link rel="icon" href="{{ $siteFavicon }}">
    <link rel="apple-touch-icon" href="{{ $siteFavicon }}">
    @endif
