{{--
    商品图缺失时的安静占位。

    用内联 SVG 而不是 emoji：emoji 在各平台的粗细和颜色差别极大（Windows 上是一个亮
    橙色包裹，Android 上是扁平灰色），而且永远比周围的界面更抢眼。描边的卡片标记跟随
    currentColor，放进哪个容器就跟着哪个容器一样安静。

    参数：
      $class - 外层类名，例如 'empty-state-glyph' | 'prod-thumb-ph'。
               每个调用点都会传；这里的兜底只是为了让某天忘了传的调用点也能落到一个
               样式表里确实定义过的类上。

    svg 上写死 56×56：本主题的容器（商品卡海报位、详情页大图位）是模板那套类，
    样式表里没有给这个占位符定过尺寸。没有宽高的 <svg> 在浏览器里默认按 300×150 渲染，
    会把海报位整个撑破。属性优先级低于任何 CSS 规则，所以 front.css 的
    `.empty-state-glyph svg { width: 56px }` 之类照样能改写它，属性只在没人管的时候兜底。
--}}
@php $placeholderClass = $class ?? 'prod-thumb-ph'; @endphp
<div class="{{ $placeholderClass }}" aria-hidden="true">
    <svg viewBox="0 0 24 24" width="56" height="56" fill="none" stroke="currentColor" stroke-width="1.4"
         stroke-linecap="round" stroke-linejoin="round" focusable="false" role="presentation">
        <rect x="2.5" y="5.5" width="19" height="13" rx="2"></rect>
        <path d="M2.5 10h19"></path>
        <path d="M6.5 14.5h4"></path>
    </svg>
</div>
