# 前台模板开发

CardShop 提供 `default`、`modern`、`minimal` 三套模板。前台使用 Blade，资源位于 `public/themes/` 和 `public/js/`，无需单独启动前台 Node 服务。

## 切换模板

店主在「系统设置 → 基本设置 → 前台模板」切换，保存后刷新前台即可，无需重启。三套模板均提供首页、分类、商品详情、查单、支付、交付结果和文章页面。

## 新建模板

模板目录位于 `resources/views/templates/`，合法目录会被设置页识别。名称只允许小写字母、数字、`-`、`_`，最长 32 位。

```text
resources/views/templates/your-theme/
    layout.blade.php
    home.blade.php
    product/list.blade.php
    partials/
public/themes/your-theme/
    style.css
```

- 页面使用 `@extends(theme_view_path('layout'))` 引用布局。
- 模板内部使用 `@themeInclude('partials.x')` 引用片段。
- 样式与脚本使用 `theme_asset('style.css')` 等辅助函数生成路径。
- 没有提供的视图会回落到 `default`；要保持完整独立风格，需覆盖全部页面及关联片段。

可以复制现有模板作为起点，一并检查布局、页面、样式与脚本。`modern` 和 `minimal` 各有独立视觉结构，公共业务片段应继续使用正确的表单字段、CSRF 和输出转义。

## 修改后检查

按移动端和桌面尺寸检查分类搜索、商品数量与优惠试算、下单、付款等待、查单、取消、卡密复制与 TXT 下载、文章及深色模式。退款关闭和开启两种状态均需检查；原款售后换卡后显示当前有效卡密。

页面试算只用于展示，提交仍由服务端计价。不要将查询密码放入 URL，不要用客户端状态决定付款成功或卡密交付；富文本继续通过应用净化流程渲染。

演示数据仅在本地开发使用，见 [演示数据说明](../database/seeders/DEMO.md)。测试范围见 [测试记录](../tests/README.md)。

[返回项目介绍](../README.md) · [首次安装](../DEPLOY.md)
