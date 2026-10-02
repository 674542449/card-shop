# CardShop · 数字商品自动交付平台

面向个人店铺的自托管发卡商城。从商品展示、阶梯优惠、付款与卡密交付，到查单、退款审核、库存预警和备份维护，在一个项目中完成。

后端采用 **Laravel 12 + PostgreSQL + Redis**，管理后台采用 **React + Ant Design**。支持易支付（支付宝/微信）、EPUSDT / BEpusdt USDT 收款，提供 Docker Compose 部署与独立的 `default`、`modern`、`minimal` 三套前台模板。

[最新版本](https://github.com/674542449/card-shop/releases/latest) · [部署指南](DEPLOY.md) · [版本发布](RELEASING.md) · [模板介绍](#前台模板) · [API 接口](#api-接口) · [回归检查](tests/README.md) · [演示数据](database/seeders/DEMO.md)

> **要在新服务器上部署？** 直接看 **[DEPLOY.md](DEPLOY.md)** —— 从空机器和新域名开始，
> 一步一步到店铺能收单，每一步都带「怎么确认这步成功了」。本文件是功能与配置参考，
> 不是部署手册。

## 当前版本

当前正式版本为 **[v1.0.2](https://github.com/674542449/card-shop/releases/tag/v1.0.2)**，发布于 **2026-10-02**。代码统一在 `main` 维护；本说明按当前实现核对，固定版本源码可从对应 Release 获取。

此版本修复实际请求复查发现的员工权限保存失败、设置队列跨账户残留、后台编辑器未净化 HTML、数据库异常回显和密码编码边界；发货 SMTP 强制执行所选 TLS 模式，历史查单限制昂贵密码计算并保留完整分页。人民币回执金额与数据库精度一致。升级兼容性变化见 [发布说明](https://github.com/674542449/card-shop/releases/tag/v1.0.2)。

每次发布使用新的递增标签，默认下一版为 `v1.0.3`。旧 `master` 部署需先按 [分支迁移步骤](RELEASING.md#旧部署从-master-迁移) 切换到 `main`。

## 你可以用它做什么

| 场景 | 已实现能力 |
| --- | --- |
| 开设数字商品店铺 | 分类与全目录搜索、商品说明、阶梯价、优惠码、移动端页面及深色模式 |
| 完成购买和交付 | 服务端计价、预留库存、支付验签、幂等发卡、复制与 TXT 下载、凭邮箱与密码找回订单 |
| 处理异常交易 | 超时释放、取消订单、迟到付款自动恢复或人工核对、重复付款核对、主动对账、退款申请与凭证登记 |
| 管理日常经营 | 商品与卡密导入、销售和退款统计、低库存提醒、通知队列与失败重试、权限管理 |
| 对接和维护 | 可限制范围的 API 令牌、SEO 推送队列、备份恢复、素材归档、记录保留与运行健康 |

后台构建产物随仓库提交，服务器宿主机无需安装 Node.js。修改后台源码时，需要在构建机器重新构建并提交完整产物；PHP Docker 镜像自带 Node.js，供资源缺失时的构建回退使用。

## 界面预览

以下截图来自本地演示或测试商城，商品、订单和卡密均为虚拟数据。

<details open>
<summary><strong>modern：暖纸色、陶土色交互与衬线标题</strong></summary>

![modern 商品首页](docs/screenshots/modern-home.png)

</details>

<details>
<summary><strong>minimal：黑白石墨风格与独立购买页面</strong></summary>

![minimal 商品首页](docs/screenshots/minimal-home.png)

</details>

<details>
<summary><strong>管理后台：交易、退款、库存与通知集中处理</strong></summary>

![管理后台概览](docs/screenshots/admin-dashboard.png)

</details>

## 功能概览

### 前台

- **商品展示** — 分类浏览、全目录商品与分类名称搜索、独立的 default / modern / minimal 页面，商品说明支持安全渲染的富文本和 Markdown
- **下单试算** — 阶梯单价、优惠码校验和预计实付；试算不占库存或优惠次数，最终实付最低 ¥0.01
- **自动发卡** — 服务端核验有效付款后交付卡密，邮件进入持久队列发送；异常付款按库存与订单状态进入人工核对
- **订单查询** — 凭邮箱 + 查询密码查看历史订单和卡密，取消未付款订单、提交退款申请
- **文章栏目** — 富文本与 Markdown 内容，支持文章内嵌商品卡片引流
- **SEO 优化** — 独立 TDK、Sitemap 自动生成、JSON-LD 结构化数据、百度推送、Bing IndexNow

### 支付

- **易支付** — 支付宝、微信扫码支付
- **USDT 链上支付** — BEpusdt 支持指定 TRC20 / BEP20 / Polygon，原版 EPUSDT 使用网关默认网络。网关可选
  [epusdt](https://github.com/assimon/epusdt) 或
  [BEpusdt](https://github.com/v03413/BEpusdt)

  在**系统设置 → USDT 支付 → 网关类型**里按实际网关选择。BEpusdt 可通过 `trade_type`
  指定网络，原版 EPUSDT 使用网关默认网络。主动对账还要求网关返回可核对的订单、流水与金额；
  不兼容的网关版本使用签名回调或人工核对，详见 [部署指南](DEPLOY.md#运行健康与主动对账)。

### 后台管理

- 仪表盘（销售统计、退款与净销售额、待审核退款、趋势图、库存预警）
- 商品管理（分类、批量导入卡密、批发价设置）
- 订单管理（筛选、手动补发、掉单处理、CSV 导出）
- 文章管理（富文本编辑器、商品卡片、封面图、SEO 字段）
- 优惠码管理（固定/百分比折扣、有效期、使用限制）
- 黑名单管理（IP / 邮箱、扫描来源与到期时间、人工解除临时封禁）
- 操作日志（全部关键操作审计记录）
- 系统设置（站点、支付、邮件、Telegram、SEO、安全）
- 管理账户与功能权限、API 令牌、退款审核、通知重试、素材归档与运行健康

### 通知与集成

- **Telegram Bot** — 已付款订单、异常付款核对与库存预警通知，通过持久队列投递并重试失败任务
- **邮件通知** — SMTP 发送卡密，自定义邮件模板
- **RESTful API** — Token 鉴权，支持第三方对接

### 安全

- Cloudflare Turnstile 人机验证
- Redis 请求限流按下单、试算、付款轮询、查单等操作分别计数，避免正常付款等待耗尽下单额度
- 网页每 IP 最多保留 3 笔待付款订单，事务锁防止并发突破；到期订单实际释放库存后才恢复额度
- 支付回调 timing-safe 签名验证
- 数据库行锁和 Redis 锁防并发超卖
- CSRF 防护、bcrypt 密码哈希、登录失败锁定；验证失败不将查询密码或人机验证令牌写入表单回填
- 后台和 API 按服务器匹配到的路由判定权限，编码路径不能绕过，未配置权限的新接口默认拒绝
- 数量、金额、外键与文件类型在服务端校验；价格、折扣、令牌归属、付款状态与卡密由服务器决定
- 查询密码同时限制字符数及 bcrypt 的 72 字节边界；网页与 API 共享猜密额度，已验证的 API 订单轮询单独处理
- 批量查单逐个核验真实密码哈希；浏览器取卡授权固定 30 分钟，并绑定订单及当前邮箱、密码。凭据变更立即拒绝旧授权，授权成功销毁旧会话 ID
- 卡密默认不参与模型 JSON 序列化；员工查看商品、订单不包含卡密，人工确认付款需要独立资金权限；支付、邮件、Telegram 与安全配置仅店主可改
- 所有动态响应禁止缓存、隐藏 Referer 并拒绝被 iframe 嵌入；私有备份目录不注册通用文件上传/下载路由。SMTP、Telegram 和支付网关错误只返回或记录安全分类
- 下单与查单凭据只接受请求正文；拒绝非法 UTF-8、NUL 与 bcrypt 截断口令。业务提示使用明确异常类型，数据库及 HTTP 异常不会作为买家错误提示回显
- 设置保存队列在会话变化时清空并取消旧请求；跨页面切换账户时同步失效，服务器校验旧页面的账户上下文。后台富文本编辑器在解析 HTML 前净化，前台仍保留独立净化
- SMTP 的 SSL/TLS 模式不允许降级为明文，包括旧式 HELO、重新连接、环境回退和组合发送中的 SMTP 子传输；默认不回退日志发送卡密，明确选择 `none` 才允许明文
- USDT 创建交易要求 HTTPS（回环开发地址除外），BEpusdt 固定人民币计价；已返回的订单、金额、币种与交易号必须一致，合法异常收款保留回执待人工核对
- 富文本和 Markdown 统一白名单净化，联系及支付链接只接受允许的协议；支付回调地址固定使用 `APP_URL`
- IP / 邮箱黑名单；敏感路径扫描防护可配置临时封禁、白名单和启停

## 技术栈

| 组件 | v1.0.2 锁定版本或部署配置 |
|------|------|
| PHP | Docker 镜像使用 8.3；应用要求 ^8.3 |
| Laravel | 12.69.3 |
| CommonMark / HTMLPurifier | 2.10.3 / 4.19.0 |
| React / Ant Design | 18.3.1 / 5.29.3 |
| React Router / Vite | 7.18.4 / 7.3.6 |
| 后台 HTML 净化 / DOM 检查 | DOMPurify 3.4.16 / jsdom 30.1.1（开发依赖） |
| 后台构建 Node.js | Docker 使用 24 LTS；本机验证 24.19.0 |
| PostgreSQL | 17 |
| Redis | 7 |
| Nginx | stable 镜像标签 |
| Docker Compose | `docker compose` CLI 插件，需支持 `up --wait` |

PHP 与前端包版本由 `composer.lock` 和 `admin-frontend/package-lock.json` 锁定；容器镜像标签见 `docker-compose.yml` 与 `docker/php/Dockerfile`。

## 验证与开发

2026-10-02 在独立数据库、缓存和文件目录中重新执行真实 HTTP、SMTP/TLS 和独立 PHP 进程并发检查。后台覆盖 72 条动作及不同权限；三套模板检查订单凭据、卡密 HTML 转义与 TXT 下载；支付检查依据付款、库存和回执变化，而非只看通知成功响应。61 条独立密码哈希的历史订单实际分页取回，没有重复或遗漏。已复现问题保留修复前后记录，详见 [测试记录](tests/README.md)。本轮没有可复用浏览器标签页，因此没有新增界面点击结论；真实网关、生产代理与未知攻击组合仍需对应环境验证。

发布前补修 Docker 依赖安装检查，**7 类 Shell 启动回归全部通过**；旧版隔离基线能捕获仅修改锁文件却跳过安装的问题。

已覆盖客户端价格篡改、零元与不足额回调、签名伪造、重复通知、并发库存及额度、越权查单，以及常见 SQL/XSS 输入。界面测试使用隔离数据库和本地支付替身；真实网关到账、外部通知投递与 Docker 运行仍需在目标环境验证。

修改后台源码及运行 DOM 检查时，使用 Node.js 22.22.2+、24.15.0+ 或 26+；推荐 24 LTS。Docker 的构建回退已同步到 24 LTS，避免使用已结束维护的 Node.js 20（[官方支持周期](https://nodejs.org/en/about/eol)）。运行：

```bash
cd admin-frontend
npm ci
npm test
npm run build
cd ..
sh docker/php/spa-stamp.sh > public/admin-assets/.build-stamp
```

将后台源码、完整的 `public/admin-assets/` 和构建标记一起提交，避免部署后管理后台资源缺失或版本不一致。前台页面由 Blade 模板及 `public/themes/`、`public/js/` 组成。

本地体验完整目录与订单状态可选用 [DemoShopSeeder](database/seeders/DEMO.md)：生成 48 件商品、180 笔演示订单等虚拟数据，仅允许本机开发环境运行，默认部署不会自动导入。

## 环境要求

### 服务器要求

| | 最低 | 推荐 | 说明 |
|---|---|---|---|
| 内存 | 1 GB | 2 GB | 1 GB 机器建议配置 swap；首次安装依赖和构建是峰值，实际占用随流量、库存和配置变化 |
| CPU | 1 核 | 2 核 | 订单查询要跑 bcrypt，属 CPU 密集 |
| 磁盘 | 20 GB | 40 GB | 为镜像、数据库、上传素材与备份预留空间 |

- Linux 宿主机以 Debian 12/13 或 Ubuntu 22.04/24.04/26.04 LTS 为参考，Docker 安装按 [Debian 官方步骤](https://docs.docker.com/engine/install/debian/) 或 [Ubuntu 官方步骤](https://docs.docker.com/engine/install/ubuntu/) 选择与系统匹配的软件源。
- 开放端口：80、443 —— **建议只对 Cloudflare 回源网段开放**，不要对公网全开。
  注意 `ufw` 挡不住 Docker 发布的端口（Docker 直接往 nat 表写规则），必须走 iptables
  的 `DOCKER-USER` 链或云控制台的安全列表。仓库里的 `scripts/cf-only-firewall.sh`
  就是干这件事的，见下面的「HTTPS 与源站防护」。

**配置越好不等于跑得越快** —— 镜像里 PHP-FPM 的默认值只有 5 个工作进程，也就是同时
只能处理 5 个请求，跟机器多大无关。部署时跑一次 `./install.sh`，它会读取本机的 CPU
和内存算出推荐值并让你选，写进 `.env`。新环境运行 `docker compose up -d` 应用参数；
已有部署运行 `./scripts/update.sh --redeploy`，让容器重新读取 Compose 环境参数并重载应用与常驻任务。

```bash
./install.sh              # 交互选择
./install.sh --show       # 只看检测结果和推荐值，不改任何文件
./install.sh --recommended # 直接采用推荐值，不提问
```

它会同时设置 PHP-FPM 的进程数和 PostgreSQL 的内存参数，并保证数据库的最大连接数
高于 PHP 进程数 —— 两者不匹配会在高峰期出现难以排查的间歇性故障。

### 宿主机需安装

| 软件 | 版本 | 安装方式 |
|------|------|----------|
| Docker Engine | 与宿主机发行版匹配的受支持版本 | Docker 官方软件源 |
| Docker Compose | 支持本项目参数的 CLI 插件 | 安装 `docker-compose-plugin`，详见 [官方步骤](https://docs.docker.com/compose/install/linux/) |
| Git | 2.x | 系统包管理器 |

先按上面的官方步骤安装 Docker Engine 与 Compose 插件，再安装 Git 并验证：

```bash
sudo apt update
sudo apt install -y git curl
docker --version
docker compose version
```

Docker 命令需由有 Docker 权限的用户执行；普通用户的权限配置与重新登录步骤见官方安装文档。

### 容器内自动安装的依赖

系统包与 PHP 扩展由 Dockerfile 在镜像构建时安装；Composer 包在容器启动时按锁文件安装，无需在宿主机手工安装：

**系统包：**

- libpq-dev（PostgreSQL 客户端库）
- libzip-dev（ZIP 压缩支持）
- libicu-dev（国际化支持）
- PostgreSQL 17 客户端（数据库备份与恢复）
- unzip、git、curl

**PHP 扩展：**

| 扩展 | 用途 |
|------|------|
| pdo_pgsql | PostgreSQL 数据库连接 |
| pgsql | PostgreSQL 原生函数 |
| redis | Redis 缓存/会话/锁 |
| zip | ZIP 文件处理 |
| intl | 国际化与本地化 |
| bcmath | 精确金额计算 |
| opcache | PHP 字节码缓存（性能优化） |

**Composer 依赖（自动安装）：**

| 包名 | 用途 |
|------|------|
| laravel/framework 12.69.3 | Laravel 框架 |
| league/commonmark 2.10.3 | Markdown 渲染（文章和商品描述） |
| ezyang/htmlpurifier 4.19.0 | 商品与文章 HTML 白名单净化 |

PHP 包由容器启动时执行 `composer install --no-dev` 按锁文件安装。启动检查同时覆盖
`composer.json` 与 `composer.lock`，任一变化都会重装；安装失败停止启动，不自动执行
`composer update` 或写入成功标记。

### 外部服务（按需配置）

| 服务 | 必需 | 说明 |
|------|------|------|
| [epusdt](https://github.com/assimon/epusdt) 或 [BEpusdt](https://github.com/v03413/BEpusdt) | 否 | USDT 收款需单独部署其中一个，部署后在后台选择对应的网关类型 |
| 易支付平台 | 否 | 支付宝/微信收款需注册商户 |
| SMTP 邮箱 | 建议 | 发送卡密邮件（QQ邮箱、Gmail 等均可） |
| Telegram Bot | 否 | 订单通知推送 |
| Cloudflare Turnstile | 建议 | 防机器人刷单 |
| 百度站长平台 | 否 | SEO 主动推送 |
| Bing Webmaster | 否 | IndexNow 收录推送 |

## 安装部署

### 1. 克隆项目

```bash
git clone --branch main https://github.com/674542449/card-shop.git
cd card-shop
```

### 2. 配置环境变量

```bash
cp .env.example .env
```

编辑 `.env` 文件，修改以下关键配置：

```env
# 应用
APP_URL=https://你的域名
APP_ENV=production
APP_DEBUG=false

# 数据库（与 docker-compose.yml 中一致）
DB_DATABASE=cardshop
DB_USERNAME=cardshop
DB_PASSWORD=修改为强密码

# Redis
REDIS_PASSWORD=null
```

数据库密码只需要改 `.env` 这一处，`docker-compose.yml` 会自动读取
（`POSTGRES_PASSWORD: ${DB_PASSWORD:-secret}`）。**不要去改 docker-compose.yml** ——
那是被 git 跟踪的文件，改了以后每次 `git pull` 升级都会冲突。

更省事的做法是直接跑 `./install.sh`：首次部署时它会检测到密码还是默认的 `secret`，
自动生成一个随机强密码写进 `.env`。

### 3. 启动容器

```bash
docker compose up -d --build
```

首次构建需要几分钟，会自动安装 PHP 依赖。

### 4. 初始化应用

**不需要手工做任何事。** 容器首次启动时会自动生成 `APP_KEY`、建好存储软链接、
执行数据库迁移和种子数据。看进度：

```bash
docker compose logs -f app
```

### 5. 访问

- 前台：`https://你的域名`
- 后台：`https://你的域名/admin`（路径可改，见 `.env` 的 `ADMIN_PATH`）

如果按下面「HTTPS 与源站防护」配了防火墙，用 IP 直连源站是**故意封掉的**，
访问不通不是故障。`APP_URL` 应与实际访问协议和域名一致：生产使用 `https://`，
本机纯 HTTP 开发使用 `http://`。会话 cookie 的 `Secure` 标志默认跟随该协议，见 `config/session.php`。

默认管理员用户名为 `admin`，可在首次启动前通过 `.env` 的 `ADMIN_USERNAME` 修改。
**管理员密码没有固定默认值。** 首次启动时生成随机密码，保存在容器内的受限文件中；通过有权限的终端读取，不写入启动日志：

```bash
docker compose exec app cat storage/app/initial-admin-password.txt
```

也可以在 `.env` 里预先设置 `ADMIN_PASSWORD`（至少 12 位），首次启动就用它建号。

忘记密码时用这个命令重置（生产镜像没有安装 tinker）：

```bash
docker compose exec app php artisan admin:password
```

登录后在「我的账户」中修改密码。

## 前台模板

后台 → 系统设置 → **前台模板** 中切换，立刻生效，不用重启。自带三套：

| 目录 | 名称 | 长什么样 |
|---|---|---|
| `default` | 默认（表格式） | 商品按分类分组，每组一张表，信息密度高 |
| `modern` | 暖纸风格 | Claude 风格的暖白、陶土色与衬线标题，侧栏目录、独立详情与交付页 |
| `minimal` | 极简石墨 | 黑白灰、无衬线标题、横向分类与大留白，独立商品、订单和文章页面 |

三套模板均覆盖首页、分类、商品详情、查单、结果、支付、交付和文章页面。`modern` 与 `minimal` 使用各自的页面结构和样式，不依赖 `default` 的视觉风格。

深色模式跟随系统偏好，页头有开关，选择存在 `localStorage`。初始化脚本
内联在 `<head>` 里，所以不会出现「先白闪一下再变深」。

### 自己加一套

模板就是 `resources/views/templates/` 下的一个目录，**建好目录就会出现在下拉框里**，
不需要注册、不需要改代码：

```
resources/views/templates/你的模板名/
    layout.blade.php          建议提供独立布局
    home.blade.php            想改哪页就放哪页
    product/list.blade.php
    partials/xxx.blade.php
public/themes/你的模板名/
    style.css                 用 theme_asset('style.css') 引用
```

三条规矩：

1. **没提供的视图会自动回落到 `default`。** 新模板可以逐页覆盖；需要完整独立风格时，应同时覆盖所有页面及相关 partial，避免回落导致风格混用。
2. **页面用 `@extends(theme_view_path('layout'))`**，不要写死 `templates.xxx.layout`。
   这样回落过来的页面会套在**你的**布局里，而不是默认布局。
3. **互相引用用 `@themeInclude('partials.x')`** 而不是 `@include`。它同样走回落，
   所以你的 partial 覆盖得了默认的，没覆盖的也能用。

可以复制现有模板作为起点，再修改布局、视图与主题资产。`minimal` 和 `modern` 各自有独立样式及脚本；复制时一并检查商品、订单和文章页面，避免遗漏后自动回落产生风格混用。

模板名只允许小写字母、数字和 `-` `_`，最长 32 位——这个值会拼进视图路径，所以 `theme()`
会校验它真实存在，不存在就回落 `default`。

---

## HTTPS 与源站防护

挂 Cloudflare 的标准做法，四步。前两步保证回源加密，后两步保证别人绕不过 CDN。

### 1. 真实访客 IP（已内置，无需配置）

`docker/nginx/default.conf` 里配好了 Cloudflare 的全部回源网段（`set_real_ip_from`）
和 `real_ip_header CF-Connecting-IP`，nginx 会在 PHP 看到请求之前把 `$remote_addr`
换成真实访客地址，而且**只在对端确实属于那些网段时**才换。

所以 `.env` 里的 `TRUSTED_PROXIES` 要**保持为空**。填 `*` 反而危险：那等于"谁连过来
都信它自称的 IP"，任何人直连源站伪造 `X-Forwarded-For` 就能绕过限流、绕过每 IP 最多
3 笔未支付订单的限制、绕过 IP 黑名单。

使用本文的 Cloudflare 源站防护方案时，DNS 记录需开启橙云代理。灰云流量直接访问源站，
不会经过 Cloudflare 防护；已限制仅 Cloudflare 回源时会无法访问。直连时 nginx 保留连接方 IP，
不会因为没有橙云而把所有访客 IP 合并。

### 2. 源站证书（回源加密）

不做这一步，Cloudflare 的 SSL 模式只能停在 Flexible，CF 到你服务器这一段是**明文**，
而这一段跑的是管理员会话 cookie 和发给买家的卡密。

**这三步要按顺序做，不要整块复制粘贴。** 证书还没放进去就先建 `ssl.conf`，nginx 会
因为找不到证书文件而**完全起不来**——连原本正常的 80 端口一起没了，站点从「能访问」
变成「彻底下线」，而报错指向证书，很容易误判。

**① 签证书**：Cloudflare 面板 → SSL/TLS → Origin Server → Create Certificate。

**② 把证书放到服务器**：

```bash
mkdir -p /opt/cf
```

然后把签出来的两段内容分别粘贴进 `/opt/cf/cert.pem` 和 `/opt/cf/key.pem`（用
`nano /opt/cf/cert.pem` 之类），再收紧私钥权限：

```bash
chmod 600 /opt/cf/key.pem
```

**③ 启用 443 监听**——不要手工 `cp`，重跑安装脚本即可：

```bash
./install.sh --recommended && docker compose up -d --force-recreate nginx
```

它只在**确认两个证书文件都在**时才创建 `docker/nginx/tls/ssl.conf`，所以不存在
「配置建好了但证书还没到位」这个能把站点搞挂的中间状态。

**`--force-recreate nginx` 不能省。** `ssl.conf` 是通过 bind mount 进容器的，新建这个
文件容器里立刻能看到——但 nginx 只在启动时读一次配置，不重建容器它就一直用旧配置，
443 永远起不来。而下一步把 Cloudflare 改成 Full (strict) 之后，CF 连不上 443，站点直接
521 下线。不带 `--force-recreate` 的 `docker compose up -d` 在 compose 配置没变时不会
重建任何容器，只会输出一行 Running。

`docker/nginx/tls/*.conf` 被 gitignore 忽略，所以你的证书路径不会跟 `git pull` 冲突。
少了 `ssl.conf` 这一步的现象是「443 端口通了但连不上 HTTPS」，很容易误判成证书问题 ——
因为 `default.conf` 里那条 `include /etc/nginx/tls/*.conf` 匹配不到文件就完全不监听 443。

然后在 Cloudflare 面板把 SSL/TLS 模式设为 **Full (strict)**，并开启 **Always Use
HTTPS**（中文界面叫「始终使用 HTTPS」）和 **HSTS**。

### 3. 只让 Cloudflare 连得上源站

`docker-compose.yml` 把 80/443 发布在 `0.0.0.0`，而 Docker 是直接往 nat 表写规则的 ——
**`ufw` 完全拦不住**。不做这一步，任何人拿到源站 IP 就能绕开 Cloudflare 的 WAF、
限速和 Bot 防护直连，CDN 等于白挂。

```bash
sudo ./scripts/cf-only-firewall.sh --check    # 先体检，不改任何规则
sudo ./scripts/cf-only-firewall.sh --apply --persist
```

`--check` 会先确认：域名确实解析到 Cloudflare 网段、经 CF 能正常访问、外网网卡识别正确、
端口映射一致、有没有 IPv6 旁路。任何一项不满足就拒绝执行 —— 在没挂 CDN 的机器上应用
这套规则，等于把站点对所有人封死，而且规则藏在 `DOCKER-USER` 链里，`ufw status` 是空的、
`docker ps` 是正常的，几乎不可能自行定位。

`--apply` 之后会**自动验证两个方向**：容器出站还通不通、经 Cloudflare 入站是否正常。
出站不通会自动回滚。这一条是有来历的 —— 早期手写的规则没限定入口网卡，把容器的出站
流量一起 DROP 了，表现是 USDT 支付跳转不过去、Turnstile 验证失败、Telegram 通知中断，
而入站看起来一切正常。

其他子命令：`--status` 看当前规则，`--remove` 撤销，`--iface=ens3` 手工指定网卡。
脚本只操作 `DOCKER-USER` 链，从不触碰 `INPUT`，**不会影响 SSH**。

### 4. 云控制台安全列表（Oracle / AWS 用户必看）

云厂商的安全列表/安全组是**独立于本机防火墙的另一层**，不放行 80/443 的话
Cloudflare 回不了源，站点会 522。建议这一层也只放行 Cloudflare 网段。

Oracle Cloud 注意：VCN 安全列表和 NSG 是**并集**关系（任一放行即放行），
只改一处可能不生效。

### 一键自检

```bash
cd ~/card-shop && sudo ./scripts/doctor.sh        # 只检查
cd ~/card-shop && sudo ./scripts/doctor.sh --fix  # 顺便修能安全修的
```

域名从 `.env` 读，不用填。检查配置、权限、容器、页面渲染、Cloudflare、证书、
容器出站、防火墙和开机自启，跳过的项也会计数列出。详见 [DEPLOY.md](DEPLOY.md) 第 8 步。

### 维护

Cloudflare 的回源网段可能调整。`cf-only-firewall.sh` 每次运行都会重新拉取最新列表，
建议每月跑一次：

```bash
sudo ./scripts/cf-only-firewall.sh --apply --yes
```

`docker/nginx/default.conf` 里那份列表需要手工同步（文件里标注了抓取日期）。

---

## 换服务器 / 换域名

搬站按这个顺序走。每一步都标了「不做会怎样」，因为这里大部分坑的共同点是**不报错**。

### 1. 先把 DNS 指过去（这一步在最前面）

在 Cloudflare 里把域名的 A 记录指向**新服务器 IP**，并开启橙云代理（橙色云朵）。

不先做这步的话，后面所有验证都没法进行：容器全起来了、日志全绿、管理员密码也拿到了，
然后 `https://新域名` 打不开——因为 DNS 还解析到旧机器，或者根本没解析。这时候人会去
查 nginx、查防火墙、查 APP_URL，而真正缺的只是一条 DNS 记录。

使用 Cloudflare 源站防护时需开启橙云；灰云会绕过 CDN，且无法通过仅允许 Cloudflare 回源的防火墙。
nginx 只对可信 Cloudflare 来源还原访客 IP；直连流量保留连接方 IP。

### 2. 部署代码，设好新域名

```bash
git clone https://github.com/674542449/card-shop.git && cd card-shop
./install.sh
```

脚本会问你域名，直接填新域名即可（不用带 `https://`），它会写进 `APP_URL`。这个值决定
会话 cookie 的 `Secure` 标志、资源链接的协议、canonical、支付回调和发给买家的订单链接——填错的
典型症状是后台登不进去，或者 HTTPS 页面加载不到 CSS。

然后 `docker compose up -d --build`。首次启动要装 PHP 依赖，慢的机器上可能几分钟。

### 3. 核对支付回调使用的新域名

支付回调与返回链接固定使用 `.env` 的 `APP_URL`，客户端请求的 Host 不会改变发给网关的地址。
搬站后必须将 `APP_URL` 更新为实际可访问的 `https://新域名`，清除已有配置缓存并重启应用、
调度器和通知进程，同时核对支付网关的域名白名单。填错或仍保留旧域名时，网关可能无法把付款结果通知到新站。

**测试订单走 `https://新域名/`。** 除了回调可达性，还要验证正式域名下的证书、会话和人机验证。
DNS 还没生效就先等；本机 hosts 只能帮助本机访问，无法让支付网关解析到新服务器。

### 4. 迁数据（如果要保留旧站的订单和卡密）

优先按 [完整备份与恢复](DEPLOY.md#备份与恢复) 操作，包含数据库、`.env`、上传素材及私有归档。
迁移窗口须暂停 Web、调度器和通知进程，避免旧、新站同时处理订单。保留旧站 `APP_KEY`，
数据库连接和域名配置按目标环境调整；完整恢复只有显式使用 `--include-config` 才覆盖 `.env`。

下面是**仅迁移数据库**的手工方案，不包含密钥与文件。在迁移期间保持业务进程暂停。

在**旧**服务器上导出：

```bash
cd ~/card-shop && docker compose exec -T postgres pg_dump -U cardshop --clean --if-exists cardshop | gzip > backup.sql.gz
```

传到新服务器后，确认目标 PostgreSQL 已启动、目标库已创建，再导入：

```bash
gunzip -c backup.sql.gz | docker compose exec -T postgres psql -U cardshop -d cardshop -v ON_ERROR_STOP=1
```

`--clean --if-exists` 让脚本先删同名表再建（否则导进已经跑过迁移的库，全是「已存在」
错误）；`-v ON_ERROR_STOP=1` 让 psql 遇错即停并返回非零 —— **默认它会跳过错误跑完并
返回 0**，于是你以为导成功了，实际只导进去一半。

手工方案还需迁移 `storage/app/public/uploads/`、私有素材及记录归档，并确认 `APP_KEY` 与原站一致。
导入后执行数据库迁移、清理配置与应用缓存，再恢复应用、调度和通知服务；Docker 部署可按
[升级步骤](DEPLOY.md#升级) 使用 `./scripts/update.sh --redeploy --build` 完成重载，随后核对订单、库存与素材。

### 5. 换域名后必须在后台重新设置的东西

数据库里存着一批**跟域名绑定**的设置，直接搬库过来它们全是旧值：

| 设置项 | 不改的后果 |
|---|---|
| **Turnstile Site Key / Secret Key** | 最坑的一个。这对 key 在 Cloudflare 侧绑定了 hostname，新域名不在列表里，`siteverify` 一律返回失败，于是**下单和订单查询全部被拒**，提示「人机验证失败，请重试」。而前台那个验证组件是正常渲染、能划过的，看起来完全不像配置问题。请在 Cloudflare 的 Turnstile 面板给新域名新建一个 widget，把新 key 填进后台。 |
| 支付网关回调 / 白名单 | 易支付和 USDT 网关那边如果配了回调域名白名单，要加上新域名。 |
| 站点名称、SEO 标题/描述 | 里面可能写了旧域名。 |
| 邮件模板 / SMTP 发件人 | 发件域名与新站不一致会影响送达率。 |

### 6. 证书、防火墙

证书是按域名签的，**新域名要重新签一张**，旧的不能用。按上面「HTTPS 与源站防护」第 2 步做。

防火墙要在新服务器上重新装一次（规则在内核里，不随代码走）：

```bash
sudo ./scripts/cf-only-firewall.sh --check
sudo ./scripts/cf-only-firewall.sh --apply --persist
```

### 7. 收尾核对

```bash
./install.sh --show          # 上线前体检，只读
```

然后从**外网**确认两件事：经 `https://新域名/` 能正常访问，直连 `新服务器IP:443` 不通。
两个方向都要验——只验一边会漏掉「防火墙把容器出站也切了」这类故障，那种故障的表现是
支付跳转不过去，几乎没人会联想到防火墙。

旧站先别急着关。等新站跑通一整天、确认订单和支付都正常，再下线。

---

## 部署后配置

登录后台「系统设置」完成以下配置：

### 支付设置

- **易支付**：填写 API 地址、商户 ID、商户密钥
- **EPUSDT**：填写 API 地址和 Token（需要单独部署 [epusdt](https://github.com/assimon/epusdt) 或 [BEpusdt](https://github.com/v03413/BEpusdt)，两者的 trade_type 不同，部署了哪个就在后台选哪个网关类型）

### 邮件设置

在后台**系统设置 → 邮件发送**配置 SMTP 主机、端口、加密方式、用户名、密码和发件人，
保存后使用「发送测试邮件」验证。邮件主题与正文模板同样在后台配置。
未填写后台 SMTP 主机时，兼容回退到部署环境的 `MAIL_*` 配置。

支持的模板变量：

- `{{site_name}}` — 站点名称
- `{{order_no}}` — 订单号
- `{{product_name}}` — 商品名称
- `{{quantity}}` — 数量
- `{{total_amount}}` / `{{amount}}` — 实付金额，两个名称兼容
- `{{cards}}` — 卡密内容

### Telegram 通知

1. 通过 [@BotFather](https://t.me/BotFather) 创建 Bot，获取 Token
2. 获取 Chat ID（可通过 [@userinfobot](https://t.me/userinfobot) 查询）
3. 在后台填入 Bot Token 和 Chat ID，开启通知

### Turnstile 验证码

1. 在 [Cloudflare Dashboard](https://dash.cloudflare.com/turnstile) 创建 Turnstile 站点
2. 在后台填入 Site Key 和 Secret Key

### SEO 设置

- 填写默认 TDK（标题、描述、关键词）
- 填写百度推送 Token（可选）
- 填写 Bing IndexNow Key（可选）

## API 接口

API 使用 Bearer Token 鉴权。在后台「API 令牌」中创建、停用和配置额度。
默认每令牌每分钟 120 次请求、20 次下单，同时最多占用 3 笔待付订单、100 张卡密。
额度按令牌统计，换 IP 不会重置；后台可调整。下单、查单、取消还分别受每 IP 20、30、10 次/分钟限制。首次凭据验证与错误猜测另共享网页/API 的 15 分钟额度：同邮箱与 IP 5 次、同邮箱 50 次、同 IP 20 次。成功不会清空猜密计数。

API 订单成功创建并发起支付，或首次通过密码验证后，服务器保存 10 分钟的验证证明，绑定创建令牌、订单、邮箱、密码 HMAC 和当前密码哈希，不缓存明文密码。正常批量下单查状态和重复轮询仍受上述请求额度限制，不继续消耗猜密次数；错密码、换令牌和密码变更不能复用证明。创建失败不产生证明，也不清空猜密次数。

### 权限范围

| 范围 | 对应操作 |
| --- | --- |
| `products:read` | 商品列表与详情 |
| `orders:create` | 创建订单 |
| `orders:query` | 查询本令牌创建的订单 |
| `orders:cancel` | 取消本令牌创建的未付款订单 |

后台新建令牌默认勾选前三项，需要取消功能时额外勾选 `orders:cancel`。
历史令牌的 `scopes=null` 兼容全部已映射接口；`scopes=[]` 表示无接口权限，不能把两者当作同一种空值。

### 接口与下单

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/api/v1/products` | 商品列表 |
| GET | `/api/v1/products/{id}` | 商品详情 |
| POST | `/api/v1/orders` | 创建订单 |
| POST | `/api/v1/orders/{order_no}/query` | 查询订单，正文传递邮箱与查询密码 |
| POST | `/api/v1/orders/{order_no}/cancel` | 取消本令牌创建的未付款订单，正文传递邮箱与查询密码 |

请求示例：

```bash
curl -H "Authorization: Bearer YOUR_TOKEN" \
     https://你的域名/api/v1/products
```

创建订单正文：

| 字段 | 要求 |
| --- | --- |
| `product_id` | 商品 ID；商品及分类须允许购买 |
| `quantity` | 正整数，须满足商品购买数量上下限、库存和令牌额度 |
| `email` | 合法邮箱，最多 200 个字符 |
| `query_password` | 6–50 个字符，且不超过 72 字节 |
| `payment_method` | `alipay`、`wechat`、`usdt_trc20`；BEpusdt 另支持 `usdt_bep20`、`usdt_polygon` |
| `coupon_code` | 可选优惠码，最多 50 个字符；服务端校验有效期、名额和商品范围 |

原版 EPUSDT 的 `usdt_trc20` 是兼容入口，实际使用网关默认网络；须在后台配置对应的支付网关。
价格和折扣由服务器计算，客户端提交的金额或付款状态不作为计价、付款或交付依据。

```bash
curl -X POST -H "Authorization: Bearer YOUR_TOKEN" \
     -H "Content-Type: application/json" -H "Accept: application/json" \
     --data '{"product_id":1,"quantity":1,"email":"buyer@example.test","query_password":"YOUR_QUERY_PASSWORD","payment_method":"alipay"}' \
     https://你的域名/api/v1/orders
```

成功返回 HTTP 201，`data` 包含 `order_no`、`total_amount`、`discount_amount`、`payment_method`、
`expires_at`、`payment_url` 和 `trade_id`。`payment_url` 为支付链接，`trade_id` 随网关返回，可能为 `null` 或空字符串。201 表示订单创建及支付发起成功，
卡密交付以服务端确认有效付款后的查单结果为准。

查单示例（仅示例凭据）：

```bash
curl -X POST -H "Authorization: Bearer YOUR_TOKEN" \
     -H "Content-Type: application/json" \
     --data '{"email":"buyer@example.test","query_password":"YOUR_QUERY_PASSWORD"}' \
     https://你的域名/api/v1/orders/YOUR_ORDER_NO/query
```

旧 GET 查单接口返回 405 和迁移说明，停止接受 URL 内的查询密码；新接口响应禁止缓存。API 查单仅允许创建该订单的令牌，网页订单使用前台邮箱与密码查单；不要使用另一个令牌查询已有 API 订单。

升级后，分开的创建令牌和查询令牌不能共享订单；同一令牌可调整为包含所需范围。删除令牌会解除历史订单的令牌关联，新建令牌不能查询这些旧订单；关联为空的历史订单仍可使用前台邮箱与密码查单，或由后台处理。
Nginx 访问日志不记录查询参数、Referer、请求正文或 Authorization。

取消接口需要 `orders:cancel` 范围；兼容历史 `scopes=null` 令牌。取消凭据使用与查单相同的 JSON 正文，URL 查询参数不接受密码。只有创建订单的令牌可以取消该订单，已付款或已有付款回执的订单会被拒绝。取消与支付回调使用订单行锁，释放库存和优惠次数仅执行一次；若网关已经收款但有效回调稍后才到，仍按实际付款处理并在需要时进入人工核对。

### 通知队列与库存预警

订单邮件和 Telegram 通知使用 PostgreSQL 中的 `notification_deliveries` 队列。
发货与通知入队在同一事务提交，支付回调不再等待 SMTP 或 Telegram。
Docker Compose 的 `notifications` 服务独立发送，每次失败后按 1、5、15、60 分钟退避，
最多尝试 5 次；发送进程中断的任务在 5 分钟后重新领取。后台「通知投递」查看状态并重试，
订单详情也展示邮件状态；「补发卡密」表示重新入队，而非已经送达。

```bash
docker compose up -d --build
docker compose logs --tail=50 notifications
# 单次处理，便于运维排查
docker compose exec -T app php artisan notifications:send --limit=10
```

非 Docker 部署需要常驻运行 `php artisan notifications:send --work`，
并继续运行 Laravel 调度器。Windows 的 `start-dev.ps1` 已包含通知进程。
队列采用至少一次投递：若发送服务已受理但进程在写入成功状态前中断，恢复时邮件可能重发；
订单发货仍按数据库事务保证只执行一次。

商品编辑页可设置低库存阈值，默认 5，0 表示仅售罄预警，清空关闭。
概览和商品列表直接显示预警；调度器每分钟检查，Telegram 配置后入队推送。
同一轮低库存只推送一次，补货超过阈值后重新启用提醒。
已验签且金额、渠道正确的异常付款保留流水、金额、时间及原因，后台可筛选待核对订单，
三套前台显示请勿重复支付，管理员核实后人工确认发货。

## 目录结构

```
card-shop/
├── app/
│   ├── Http/Controllers/
│   │   ├── Api/            # 对外 API 控制器
│   │   │   └── Admin/      # 后台 API 控制器
│   │   └── Front/          # 前台控制器
│   ├── Models/             # Eloquent 模型
│   ├── Services/           # 业务服务层
│   ├── Http/Middleware/    # 中间件
│   └── Http/Requests/      # 表单验证
├── admin-frontend/         # React 管理后台与构建源文件
├── config/                 # 应用配置
├── database/
│   ├── migrations/         # 数据库迁移
│   └── seeders/            # 种子数据
├── docker/                 # Docker 配置
├── public/                 # 静态资源
├── resources/views/        # Blade 模板
│   ├── admin/              # 后台入口视图
│   └── templates/          # default / modern / minimal 独立模板
└── routes/                 # 路由定义
```

## 常用命令

```bash
# 启动服务
docker compose up -d

# 停止服务
docker compose down

# 查看日志
docker compose logs -f app

# 进入 PHP 容器
docker compose exec app bash

# 清理应用与配置缓存，并重新预编译视图
docker compose exec app php artisan cache:clear
docker compose exec app php artisan config:clear
docker compose exec app php artisan view:cache

# 仅数据库备份；完整备份与恢复见 DEPLOY.md
docker compose exec -T postgres pg_dump -U cardshop --clean --if-exists cardshop > backup.sql

# 数据库恢复（覆盖目标数据，先暂停业务进程并确认备份）
docker compose exec -T postgres psql -U cardshop -d cardshop -v ON_ERROR_STOP=1 < backup.sql
```

## 更新升级

代码统一在 `main` 维护，正式版本使用递增的 `v1.0.0`、`v1.0.1` 等标签。
旧服务器仍在 `master` 时，请先按 [版本发布与分支迁移](RELEASING.md#旧部署从-master-迁移) 切换分支。

```bash
cd ~/card-shop && ./scripts/update.sh
```

自动完成：检查工作区 → 列出待更新的提交 → 判断要不要重建镜像和备份数据库 → 拉代码 →
重启 → 验证站点确实是新版。`--check` 只看不做，`--yes` 免确认，`--rollback` 回滚。

详细说明见 [DEPLOY.md](DEPLOY.md) 的「升级」一节。

更新脚本拉取当前 `main` 的最新提交；发布标签用于固定版本定位。`docker/`、`composer.json`
或 `composer.lock` 变更时会重建镜像，启动时按锁文件安装依赖。手工拉代码、迁数据或调整配置后，
可使用 `./scripts/update.sh --redeploy --build` 强制重建与重载，避免提交相同而提前退出。

## 订单、权限与维护功能

- 三个前台模板均支持全目录服务器搜索与分页；订单查询支持可选订单号精确查找，以及每批 60 条的继续查询。查询密码保留哈希，下一批凭据加密保存在短期会话中，不进入 URL。旧哈希匹配后自动补齐查询索引。新查询密码最多 50 个字符且不超过 72 字节，避免 bcrypt 静默截断中文等多字节密码。
- 新订单保存下单时商品名称，改名不会改变历史订单。升级时旧订单只能用升级当时的名称补齐，无法推断过去已经修改的名称。轮换 `APP_KEY` 前应保留备份；轮换后需将 `orders.query_password_key` 置空重新建立索引，或用订单号精确查询。
- 停用商品分类会同时隐藏并停止该分类商品的新购买，已购订单仍可查单。支付回调按服务端订单金额校验，拒绝零金额、少付款、错渠道及重复流水跨订单使用。
- 三个模板均可校验优惠码并试算当前数量的阶梯价。试算接口不创建订单、不占库存、不消耗优惠次数；数量或优惠码改变后旧报价失效，最终提交重新校验服务端价格、库存和优惠条件。全额优惠仍最低实付 ¥0.01，当前不支持免费自动发货。原版 EPUSDT 不接受指定 BEP20/Polygon 的订单，使用 BEpusdt 后才开放这些选项。
- 买家验证订单后可取消未付款订单，立即释放占用库存与优惠次数；已有付款回执的订单须先核对。API 客户端可通过正文凭据和专用范围取消自己创建的订单。
- 付款回执分别记录人民币订单金额、签名回调中的实际 USDT 金额、网络和交易哈希。额外付款进入待核对列表，店主可登记处理结果或退款。API 查单返回付款核对状态和退款摘要。
- 前台可提交退款申请；后台完成审核、退款额度控制和实际退款凭证登记。**批准申请不会调用网关转账**：操作员应在原支付渠道完成实际退款后登记流水。重复付款按各自回执控制退款余额，已交付卡密不会重新入库。
- 管理员分为店主与员工，员工按功能授予查看/操作权限；操作权限包含查看。店主管理账户启停，最后一位店主及当前店主账户不能被停用或降权。API 令牌支持有效期、接口范围、IP/CIDR 白名单及原有请求/库存占用额度。旧令牌保留原有范围，撤销或到期立即拒绝访问。
- 员工卡密权限单独使用 `cards:read` / `cards:write`，商品与订单权限不再隐含卡密读取。人工确认付款同时要求 `orders:write` 与 `payments:write`；有权限的财务人员必须实际核对收款后操作。支付、SMTP/邮件模板、Telegram、Turnstile 和扫描防护配置及 SMTP 测试仅店主可修改或执行。
- 商品改价和优惠券管理权限会影响实际应付价格，仍只应授予可信人员；关闭卡密查看权限不会撤销这些经营权限。此次权限升级不会自动给旧员工添加新增卡密或人工收款权限。
- 只读员工的列表不展示创建、编辑、删除、重试等写入操作，系统设置禁用编辑与自动保存；服务端仍逐接口校验权限。仅有内容权限的编辑员可通过专用商品选项接口获取在售商品 ID 与名称，无需获得库存、卡密或商品管理权限。
- 概览按付款时间统计销售额，按退款完成时间统计已完成退款。净销售额只扣原付款退款；额外重复付款未计入原销售额，其退款仅进入退款总额。跨期退款可能使当期净销售额为负，这些指标不代表利润。待审核、待登记退款提供管理入口。
- 文章编辑器可插入 `[[product:商品ID]]` 商品卡片，三个模板分别渲染，读取当前价格和库存；停售商品显示不可购买提示。SEO 设置提供百度与 IndexNow 密钥、站点域名和 IndexNow 验证文件；发布/修改/撤下页面进入可重试的持久队列，维护页可提交现有页面或重试失败任务。
- 完整备份、明确目标库的恢复演练、素材归档、记录保留、独立健康监控和主动付款对账，见 [DEPLOY.md](DEPLOY.md#备份与恢复)。

## License

采用 [MIT License](LICENSE)。项目代码与依赖各自保留其版权及许可证声明。
