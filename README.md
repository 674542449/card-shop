# CardShop - 个人发卡平台

基于 Laravel 12 构建的自动发卡平台，支持易支付（支付宝/微信）和 EPUSDT（TRC20/BEP20/Polygon）多链 USDT 收款，Docker Compose 一键部署。

## 功能概览

### 前台
- **商品展示** — 分类浏览、商品详情（Markdown 渲染）、阶梯批发价
- **自动发卡** — 支付成功后自动发送卡密到页面和邮箱
- **订单查询** — 凭邮箱 + 查询密码查看历史订单和卡密
- **文章栏目** — Markdown 文章系统，支持文章内嵌商品卡片引流
- **SEO 优化** — 独立 TDK、Sitemap 自动生成、JSON-LD 结构化数据、百度推送、Bing IndexNow

### 支付
- **易支付** — 支付宝、微信扫码支付
- **USDT 链上支付** — 支持 TRC20 / BEP20 / Polygon 三条链，网关可选
  [epusdt](https://github.com/assimon/epusdt) 或
  [BEpusdt](https://github.com/v03413/BEpusdt)

  两者接口地址和签名算法完全相同，在**系统设置 → USDT 支付 → 网关类型**里选择即可。
  区别只有一处：只有 BEpusdt 支持 `trade_type` 参数，也就是**把收款锁定到买家选的那条链**；
  原版 epusdt 收到这个参数会因签名不匹配而拒绝下单，所以必须按实际部署的版本选对。

### 后台管理
- 仪表盘（销售统计、趋势图、库存预警）
- 商品管理（分类、批量导入卡密、批发价设置）
- 订单管理（筛选、手动补发、掉单处理、CSV 导出）
- 文章管理（Markdown 编辑器、封面图、SEO 字段）
- 优惠码管理（固定/百分比折扣、有效期、使用限制）
- 黑名单管理（IP / 邮箱拉黑）
- 操作日志（全部关键操作审计记录）
- 系统设置（站点、支付、邮件、Telegram、SEO、安全）

### 通知与集成
- **Telegram Bot** — 新订单、库存预警实时推送
- **邮件通知** — SMTP 发送卡密，自定义邮件模板
- **RESTful API** — Token 鉴权，支持第三方对接

### 安全
- Cloudflare Turnstile 人机验证
- Redis 滑动窗口限流
- 支付回调 timing-safe 签名验证
- Redis 分布式锁防并发超卖
- CSRF 防护、bcrypt 密码哈希、登录失败锁定
- IP / 邮箱黑名单

## 技术栈

| 组件 | 版本 |
|------|------|
| PHP | 8.3 |
| Laravel | 12 |
| PostgreSQL | 17 |
| Redis | 7 |
| Nginx | stable |
| Docker Compose | 最新 |

## 环境要求

### 服务器要求

| | 最低 | 推荐 | 说明 |
|---|---|---|---|
| 内存 | 1 GB | 2 GB | 空闲约 400–550 MB；1 GB 机器建议加 2 GB swap，首次部署时 composer 解析依赖是内存峰值 |
| CPU | 1 核 | 2 核 | 订单查询要跑 bcrypt，属 CPU 密集 |
| 磁盘 | 20 GB | 40 GB | 镜像约 2 GB |

- 操作系统：Debian 13 / Ubuntu 22.04+ / CentOS 8+（推荐 Debian 13）
- 开放端口：80、443 —— **建议只对 Cloudflare 回源网段开放**，不要对公网全开。
  注意 `ufw` 挡不住 Docker 发布的端口（Docker 直接往 nat 表写规则），必须走 iptables
  的 `DOCKER-USER` 链或云控制台的安全列表。仓库里的 `scripts/cf-only-firewall.sh`
  就是干这件事的，见下面的「HTTPS 与源站防护」。

**配置越好不等于跑得越快** —— 镜像里 PHP-FPM 的默认值只有 5 个工作进程，也就是同时
只能处理 5 个请求，跟机器多大无关。部署时跑一次 `./install.sh`，它会读取本机的 CPU
和内存算出推荐值并让你选，写进 `.env`，重启生效（不需要重新构建镜像）：

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
| Docker | 20.10+ | 见下方安装命令 |
| Docker Compose | v2+ | Docker 自带 |
| Git | 2.x | 系统包管理器 |

**Debian 13 / Ubuntu 一键安装：**

```bash
# 更新系统
apt update && apt upgrade -y

# 安装 Git
apt install -y git curl

# 安装 Docker
curl -fsSL https://get.docker.com | sh

# 将当前用户加入 docker 组（免 sudo）
usermod -aG docker $USER

# 验证安装
docker --version
docker compose version
```

**CentOS 8+ 安装：**

```bash
yum install -y git curl
curl -fsSL https://get.docker.com | sh
systemctl enable --now docker
usermod -aG docker $USER
```

### 容器内自动安装的依赖

以下依赖在 `docker compose up --build` 时由 Dockerfile 自动安装，无需手动操作：

**系统包：**
- libpq-dev（PostgreSQL 客户端库）
- libzip-dev（ZIP 压缩支持）
- libicu-dev（国际化支持）
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
| laravel/framework ^12.0 | Laravel 框架 |
| league/commonmark ^2.0 | Markdown 渲染（文章和商品描述） |

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
git clone https://github.com/674542449/card-shop.git
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
- 后台：`https://你的域名/admin`

如果按下面「HTTPS 与源站防护」配了防火墙，用 IP 直连源站是**故意封掉的**，
访问不通不是故障。另外 `APP_URL` 必须是 `https://` 开头，否则会话 cookie 拿不到
`Secure` 标志（见 `config/session.php`），后台可能登不进去。

**管理员密码不再有固定默认值。** 首次启动时会自动生成一个随机密码并打印在容器日志里，只显示一次：

```bash
docker logs cardshop-app | grep -A6 "管理员账号已创建"
```

也可以在 `.env` 里预先设置 `ADMIN_PASSWORD`（至少 12 位），首次启动就用它建号。

忘记密码时用这个命令重置（生产镜像没有安装 tinker）：

```bash
docker compose exec app php artisan admin:password
```

登录后在「系统设置 → 修改密码」里可以随时更改。

## HTTPS 与源站防护

挂 Cloudflare 的标准做法，四步。前两步保证回源加密，后两步保证别人绕不过 CDN。

### 1. 真实访客 IP（已内置，无需配置）

`docker/nginx/default.conf` 里配好了 Cloudflare 的全部回源网段（`set_real_ip_from`）
和 `real_ip_header CF-Connecting-IP`，nginx 会在 PHP 看到请求之前把 `$remote_addr`
换成真实访客地址，而且**只在对端确实属于那些网段时**才换。

所以 `.env` 里的 `TRUSTED_PROXIES` 要**保持为空**。填 `*` 反而危险：那等于"谁连过来
都信它自称的 IP"，任何人直连源站伪造 `X-Forwarded-For` 就能绕过限流、绕过每 IP 最多
3 笔未支付订单的限制、绕过 IP 黑名单。

前提是 Cloudflare 面板里那条 A 记录必须开着橙云代理。不开的话这套方案静默失效 ——
不报错，只是所有访客 IP 又变成一个。

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
./install.sh --recommended && docker compose up -d
```

它只在**确认两个证书文件都在**时才创建 `docker/nginx/tls/ssl.conf`，所以不存在
「配置建好了但证书还没到位」这个能把站点搞挂的中间状态。用 `up -d` 而不是
`restart`：如果你改过 `TLS_CERT_DIR`，挂载点变了，`restart` 是拿旧挂载重启容器，
新路径不会生效，nginx 照样找不到证书。

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
sudo ./scripts/cf-only-firewall.sh --check --domain=你的域名    # 先体检，不改任何规则
sudo ./scripts/cf-only-firewall.sh --apply --domain=你的域名 --persist
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

### 维护

Cloudflare 的回源网段一年会变几次。`cf-only-firewall.sh` 每次运行都会重新拉取最新列表，
建议每月跑一次：

```bash
sudo ./scripts/cf-only-firewall.sh --apply --domain=你的域名 --yes
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

橙云也必须开：不开的话 `docker/nginx/default.conf` 里那 22 条 `set_real_ip_from` 永远
匹配不上，真实 IP 还原**静默失效**，所有按 IP 的限流和黑名单退化成全站一个桶。

### 2. 部署代码，设好新域名

```bash
git clone https://github.com/674542449/card-shop.git && cd card-shop
./install.sh
```

脚本会问你域名，直接填新域名即可（不用带 `https://`），它会写进 `APP_URL`。这个值决定
会话 cookie 的 `Secure` 标志、资源链接的协议、canonical 和发给买家的订单链接——填错的
典型症状是后台登不进去，或者 HTTPS 页面加载不到 CSS。

然后 `docker compose up -d --build`。首次启动要装 PHP 依赖，慢的机器上可能几分钟。

### 3. 别用 IP 直接下测试单

这是搬站最容易踩的一个坑，而且症状完全指不到原因。

支付回调地址是 `url('/payment/epay/notify')` 拼的，Laravel 的 `url()` 取的是**当前请求
的 Host**，不是 `APP_URL`。你用 `http://新服务器IP/` 下一笔测试单，回调地址就被写成
`https://新服务器IP/payment/epay/notify` 发给了支付网关——网关回调打不进来（源站没有
对应证书，防火墙也只放行 Cloudflare），订单永远停在未支付，而下单流程本身一切正常。

**所有测试都走 `https://新域名/`。** DNS 还没生效就先等，或者在本机 hosts 里临时指过去。

### 4. 迁数据（如果要保留旧站的订单和卡密）

在**旧**服务器上导出：

```bash
cd ~/card-shop && docker compose exec -T postgres pg_dump -U cardshop cardshop | gzip > backup.sql.gz
```

传到新服务器后导入（新站容器要先起来，让它建好库结构，再覆盖）：

```bash
gunzip -c backup.sql.gz | docker compose exec -T postgres psql -U cardshop -d cardshop
```

上传的图片在 `storage/app/public/uploads/`，一并 rsync 过去。

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
sudo ./scripts/cf-only-firewall.sh --check --domain=新域名
sudo ./scripts/cf-only-firewall.sh --apply --domain=新域名 --persist
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
SMTP 参数在 `.env` 文件中配置，邮件模板在后台设置。

支持的模板变量：
- `{{site_name}}` — 站点名称
- `{{order_no}}` — 订单号
- `{{product_name}}` — 商品名称
- `{{quantity}}` — 数量
- `{{amount}}` — 金额
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

API 使用 Bearer Token 鉴权。在后台「系统设置」中创建 API Token。

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/api/v1/products` | 商品列表 |
| GET | `/api/v1/products/{id}` | 商品详情 |
| POST | `/api/v1/orders` | 创建订单 |
| GET | `/api/v1/orders/{order_no}` | 查询订单 |

请求示例：

```bash
curl -H "Authorization: Bearer YOUR_TOKEN" \
     https://你的域名/api/v1/products
```

## 目录结构

```
card-shop/
├── app/
│   ├── Http/Controllers/
│   │   ├── Admin/          # 后台控制器（12个）
│   │   ├── Api/            # API 控制器
│   │   └── Front/          # 前台控制器（6个）
│   ├── Models/             # Eloquent 模型（13个）
│   ├── Services/           # 业务服务层（7个）
│   ├── Http/Middleware/     # 中间件（4个）
│   └── Http/Requests/      # 表单验证（7个）
├── config/                 # 应用配置
├── database/
│   ├── migrations/         # 数据库迁移（13个）
│   └── seeders/            # 种子数据
├── docker/                 # Docker 配置
├── public/                 # 静态资源
├── resources/views/        # Blade 模板
│   ├── admin/              # 后台视图（20个）
│   ├── front/              # 前台视图（10个）
│   └── layouts/            # 布局模板
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

# 清理缓存
docker compose exec app php artisan cache:clear
docker compose exec app php artisan config:clear
docker compose exec app php artisan view:clear

# 数据库备份
docker compose exec postgres pg_dump -U cardshop cardshop > backup.sql

# 数据库恢复
docker compose exec -T postgres psql -U cardshop cardshop < backup.sql
```

## 更新升级

```bash
git pull origin master
docker compose up -d --build
docker compose exec app php artisan migrate
docker compose exec app php artisan cache:clear
```

## License

MIT
