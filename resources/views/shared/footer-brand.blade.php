{{--
    页脚的品牌行，三套模板共用。

    默认输出一行 "Powered by 独角数卡"。这是**指纹混淆**：自动化扫描器常按页脚的
    "Powered by" 字样和版本号来判断站点跑的是什么程序，再去套对应的已知漏洞。声明成
    一个常见的开源发卡程序，能让这类粗筛落空。

    要明确的是它的能力边界：稍微认真一点的攻击者看 HTML 结构、静态资源路径
    （/themes/*/style.css、/admin-assets/*）、会话 cookie 名（cardshop_session）、
    后台路由形态就能分辨出来。所以这一行是给扫描器看的，**不能拿它替代真正的加固**
    （ADMIN_PATH、CF-only 防火墙、会话绑定这些才是）。

    做成设置项而不是写死：运营随时能改成别的名字，或者清空整行不显示。
    值是纯文本，Blade 会转义，运营写进 <script> 也变不成脚本。

    这里不写年份和站名——那两样在各模板自己的版权行里，这一行只负责品牌。
--}}
@php
    // 这里读原始值而不是用 setting()：setting() 在值为空字符串时会返回默认值
    // （helpers.php 的 `($value === null || $value === '') ? $default : $value`），
    // 于是「运营把这一行清空」和「从没配过」变成同一件事，结果就是这一行永远关不掉。
    // 直接读 settings_all() 才能区分：
    //   键不存在（老站升级上来还没跑过 seeder） -> 用默认值
    //   键存在但为空（运营主动清空）           -> 整行不渲染
    $footerPoweredRaw = settings_all()['footer_powered_by'] ?? null;
    $footerPowered = trim((string) ($footerPoweredRaw ?? '独角数卡'));
@endphp
@if($footerPowered !== '')
<div class="footer-powered">Powered by {{ $footerPowered }}</div>
@endif
