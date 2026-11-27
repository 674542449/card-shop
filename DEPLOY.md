# CardShop 正式版首次安装

从一台空的 Linux 服务器和一个正式域名开始，完成 HTTPS、商城初始化、后台配置和首次下单验收。功能介绍见 [README](README.md)，对接接口见 [API 文档](docs/API.md)。

适用正式版本：**v1.0.18**，核对日期：2026-11-28。应用和 Nginx 使用同一正式版本的多架构镜像，自动化测试与容器验收范围见 [测试说明](tests/README.md)。

本文使用 **Docker Compose + Cloudflare 橙云代理**，示例域名为 **`shop.example.com`**。所有域名、服务器 IP 和账号都应替换成自己的值。服务器命令在受控的 root 管理终端执行：SSH 登录后先运行 `sudo -i`；标注「外部电脑」的验证在你自己的电脑执行。这样 Compose 能读取仅 root 与容器组可读的外部环境文件，商城不提供员工账户。

## 安装前准备

| 项目 | 要求 |
| --- | --- |
| 服务器 | 64 位 Ubuntu 或 Debian，AMD64 / x86_64 或 ARM64 / aarch64；最低 1 核 / 1 GB / 20 GB，推荐 2 核 / 2 GB / 40 GB 起 |
| 域名 | 已接入 Cloudflare，能修改 DNS、SSL/TLS 与 Turnstile 配置 |
| 工具 | Git、Bash、curl、openssl、Docker Engine、支持 `up --wait` 的 Compose CLI 插件 |
| 权限 | 能 SSH 登录，执行 Docker 命令，并使用 sudo 管理证书和防火墙 |
| 端口 | 云安全组允许 HTTPS/HTTP 回源；SSH 按自己的管理地址放行 |
| 外部服务 | 易支付或 USDT 网关至少一个；建议准备支持 SSL/TLS 的 SMTP 服务 |

宿主机无需安装 PHP、Composer 或 Node.js，镜像包含运行环境、依赖和数据库备份工具。首次安装需要网络下载发布镜像，1 GB 机器应预留 swap 和足够磁盘空间。

应用与 Nginx 的正式镜像使用同一版本的多架构标签，Docker 会为服务器自动选择 `linux/amd64` 或 `linux/arm64`，无需添加 `platform: linux/amd64`。`uname -m` 显示 `x86_64` 对应 AMD64，`aarch64` 对应 ARM64；32 位 ARM 不在支持范围。Apple Silicon 上可使用 Linux ARM64 容器进行开发，正式生产验收环境为 Linux。

## 第 1 步：准备域名与 Docker

在 Cloudflare 的 DNS 页面为正式主机名添加 A 记录，指向服务器公网 IP，并开启 **已代理（橙云）**。如果区域为 `example.com`、商城域名为 `shop.example.com`，记录名称填 `shop`。使用 `www` 时，也须为该实际主机名配置记录和证书。

安装基础工具：

```bash
sudo apt update
sudo apt install -y git curl openssl ca-certificates nano
```

Docker Engine 和 Compose 插件按对应系统的官方步骤安装：[Ubuntu](https://docs.docker.com/engine/install/ubuntu/) 或 [Debian](https://docs.docker.com/engine/install/debian/)。安装包需包含 `docker-compose-plugin`，普通用户的 Docker 权限设置也按官方步骤完成。

验证：

```bash
docker --version
docker compose version
```

两条命令均应成功；本文使用 `docker compose`，不使用独立的旧式 `docker-compose` 命令。

## 第 2 步：准备正式镜像和外部配置

```bash
git clone --branch main https://github.com/674542449/card-shop.git ~/card-shop
cd ~/card-shop
git checkout v1.0.18
sudo install -d -m 750 -o 33 -g 33 /etc/cardshop /etc/cardshop/secrets
sudo cp .env.example /etc/cardshop/runtime.env
sudo chown root:33 /etc/cardshop/runtime.env
sudo chmod 640 /etc/cardshop/runtime.env
sudo nano /etc/cardshop/runtime.env
```

> 若 GitHub 仓库设为私有，`git clone` 时请使用 SSH 密钥或具备 `repo` 权限的 Personal Access Token；拉取私有 GHCR 镜像前请先运行 `docker login ghcr.io`（使用具备 `read:packages` 权限的 Token）。

修改已有键，不要重复追加。正式配置保存在项目目录之外。`APP_KEY` 应生成一次并独立保存；可在安全终端运行 `openssl rand -base64 32`，把结果加上 `base64:` 前缀填入 `APP_KEY`，不要使用示例或重复生成。数据库与管理员密码使用独立随机强密码。

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://shop.example.com
APP_KEY=base64:你生成的32字节随机密钥
DB_DATABASE=cardshop
DB_USERNAME=cardshop
DB_PASSWORD=你的随机强密码
ADMIN_PATH=
ADMIN_USERNAME=admin
ADMIN_PASSWORD=至少12字符的随机强密码
TRUSTED_PROXIES=
TLS_CERT_DIR=/opt/cf
SHOP_ENV_FILE=/etc/cardshop/runtime.env
SHOP_SECRETS_DIR=/etc/cardshop/secrets
SHOP_KEYRING_FILE=/run/secrets/shop-keyring.json
SHOP_APP_IMAGE=ghcr.io/674542449/card-shop:v1.0.18
SHOP_WEB_IMAGE=ghcr.io/674542449/card-shop-nginx:v1.0.18
```

发布镜像在对应标签的 CI 验证与构建成功后可用。建议将镜像值固定为该运行输出的 `@sha256:...` 摘要；部署不会跟随 `main` 自动变化。若 GHCR 要求登录，使用具备读取包权限的账户；也可在构建机从本标签分别构建 `docker/php/Dockerfile.production` 的 `runtime`、`web` 目标后上传自己的镜像仓库。

固定摘要时，使用发布任务输出的**多架构索引摘要**，可在两种服务器上自动选择架构；单个平台的镜像摘要只适用于对应架构。可用 `docker buildx imagetools inspect "$SHOP_APP_IMAGE"` 检查索引中的两个平台（先在终端设置镜像变量，环境文件不会自动导出为 Shell 变量）。应用与 Nginx 必须使用同一版本，PostgreSQL / Redis 由 Compose 拉取原生架构镜像。

下文在项目目录先定义 Compose 简写，新终端需重新定义：

```bash
shop() { docker compose --env-file /etc/cardshop/runtime.env -f docker-compose.production.yml "$@"; }
shop config --quiet
shop pull app nginx
```

本方案使用独立生产 Compose；`install.sh` 和默认 `docker-compose.yml` 保留给源码开发部署。首次正式安装不需要在服务器编译 React、运行 Composer 或导入演示数据。

`APP_URL` 必须对应实际 HTTPS 域名，影响 Cookie、邮件与支付回调。Cloudflare/Nginx 方案保持 `TRUSTED_PROXIES` 为空。`ADMIN_PATH` 留空时将在首次初始化自动生成并持久化 24 位随机高强度后台路径（此时访问 `/admin` 直接返回 404）；也可自行指定最长 32 位字母、数字、`-`、`_`，但不能代替强密码或服务端权限。

## 第 3 步：启动前配置 HTTPS

先准备源站证书，再启动商城并登录。Cloudflare **SSL/TLS → 源服务器 → 创建证书**，选择覆盖实际主机名的 PEM 证书。分别保存证书和私钥；私钥离开创建页面后不能再次读取。操作依据 [Cloudflare 源站证书说明](https://developers.cloudflare.com/ssl/origin-configuration/origin-ca/)。

```bash
sudo mkdir -p /opt/cf
sudo nano /opt/cf/cert.pem
sudo nano /opt/cf/key.pem
sudo chmod 600 /opt/cf/key.pem
```

`cert.pem` 填完整证书，`key.pem` 填完整私钥，保留 BEGIN/END 行。然后在项目目录启用 TLS 配置：

```bash
cd ~/card-shop
cp docker/nginx/tls/ssl.conf.example docker/nginx/tls/ssl.conf
test -s docker/nginx/tls/ssl.conf && echo 'TLS configuration ready'
```

先检查两个证书文件和外部环境文件中的 `TLS_CERT_DIR`，再继续启动。

Nginx 的证书在容器内挂载为 `/etc/nginx/cf/`，宿主机路径可通过 `TLS_CERT_DIR` 调整。云安全组应允许 Cloudflare 回源访问 80/443；避免被其他服务占用。

Cloudflare Origin CA 证书供橙云代理回源使用，浏览器直接访问源站不会信任它；若自行采用无 CDN 的部署方案，需公开可信证书，并自行调整代理与源站防护配置。[证书适用范围](https://developers.cloudflare.com/ssl/origin-configuration/origin-ca/)

## 第 4 步：显式初始化并启动

```bash
cd ~/card-shop
shop up -d postgres redis --wait --wait-timeout 180
shop --profile init run --rm initialize
shop --profile init run --rm migrate
shop up -d --wait --wait-timeout 300
shop ps
shop exec nginx nginx -t
shop exec app php artisan shop:ready
shop exec app php artisan shop:health
```

`initialize` 在外部密钥目录创建 keyring，已有文件时拒绝覆盖。`migrate` 是单独的一次性操作，初始化数据库与基础配置、管理员；不会导入商品订单或测试卡密。运行容器使用只读镜像和只读 keyring，重启不会下载依赖、重新建库或更新数据库结构。

应有 **5 个运行服务**：`app`、`worker`、`nginx`、`postgres`、`redis`（其中 `worker` 容器内以独立子进程并行托管调度、通知、SEO、对账和备份五项后台任务）。初始化任务执行完即退出。数据库和 Redis 不发布宿主机端口。`shop:ready` 检查数据库、迁移、Redis、密钥、构建产物、存储和维护状态；`shop:health` 检查五项工作进程心跳及业务积压，启动后等约一分钟再执行。

等待失败时看 `shop logs --tail=80 app`，按实际错误修复；不要删数据库卷。初始 keyring 须单独保存到安全离线介质，保留所有版本。**只有商城备份而没有 keyring，无法恢复卡密与支付密钥。**

Cloudflare 设置 **Full (strict)** 和 **Always Use HTTPS**，核验源站证书。正式页面应包含布局标记：

```bash
curl -fsS http://127.0.0.1/ | grep -c 'csrf-token'
curl -fsS https://shop.example.com/ | grep -c 'csrf-token'
```

计数均应大于0。不要对后台、订单和 API 强制整页缓存。`/health/ready` 只允许直接回环连接或正确 `X-Shop-Probe-Token`，返回安全的就绪布尔值；容器直接使用 CLI 探针。

## 第 5 步：限制源站访问

确认正式域名已经经 Cloudflare 正常访问后，在服务器执行：

```bash
cd ~/card-shop
sudo ./scripts/cf-only-firewall.sh --check
sudo ./scripts/cf-only-firewall.sh --apply --persist
```

脚本从项目 `.env` 读取域名（先用 `ln -s /etc/cardshop/runtime.env .env` 指向受保护的外部配置文件），校验代理、网卡、端口与 IPv6 条件，再设置仅允许 Cloudflare 回源的规则及开机持久化。Docker 发布端口的流量不能仅依赖普通 UFW 规则限制，见 [Docker 防火墙说明](https://docs.docker.com/engine/network/packet-filtering-firewalls/)。脚本操作自己的 `DOCKER-USER` 规则，不修改 SSH 所用的 `INPUT` 规则。

验证入站和容器出站：

```bash
curl -fsS https://shop.example.com/ | grep -c 'csrf-token'
shop exec app curl -m 10 -fsS -o /dev/null -w '%{http_code}\n' https://api.github.com
```

页面计数应大于 0，出站检查应成功。再从 **外部电脑** 测试 `http://服务器IP/`，应无法直接取得商城页面；服务器自己访问公网 IP 不能作为这项验证的依据。

云安全组也建议只允许 Cloudflare 网段访问 80/443，同时保留自己的 SSH 管理规则。需要撤销本脚本规则时执行 `sudo ./scripts/cf-only-firewall.sh --remove`。

## 首次登录

首次安装会自动生成 24 位随机高强度后台路径（除非提前显式配置了 `ADMIN_PATH`），访问 `/admin` 会直接返回 `404`。可通过有权限的服务器终端查询后台登录地址：

```bash
shop exec app php artisan admin:path
```

默认用户名为 `admin`，或使用首次启动前设置的 `ADMIN_USERNAME`。

没有预设有效密码时，初始密码与随机后台路径一并保存在受限文件中：

```bash
shop exec app cat storage/app/initial-admin-password.txt
```

正常情况下随机密码保存于受限文件。若文件不可取，用专用命令重置，不要依赖默认密码或 `tinker`：

```bash
shop exec app php artisan admin:password admin
```

将命令末尾的 `admin` 替换为实际用户名。登录后在「账户与密码」更换密码，并删除初始密码文件。若安装时出现密码文件写入失败并打印密码的警告，还需按自己的日志保留系统清理该敏感记录。修改外部环境文件的 `ADMIN_PASSWORD` 不会修改已创建账号。

可在「账户与密码 → 登录双重验证」绑定验证器，提交验证码确认后才启用。8 个恢复码只展示一次，应离线保存，每个仅能使用一次。确保服务器时间同步，并备份 `APP_KEY`；丢失验证器及恢复码时，在受控终端执行：

```bash
shop exec app php artisan admin:2fa-reset admin
```

将末尾用户名替换为实际账号，按交互提示确认。该操作会撤销旧登录会话。

## 后台配置与首次收单

### 支付与邮件

在 **系统设置** 中配置：

| 配置 | 操作 |
| --- | --- |
| 基本设置 | 名称、Logo、前台模板、公告、联系方式 |
| 支付设置 → EPay 支付 | 网关地址、商户 ID、商户密钥 |
| 支付设置 → USDT 支付 | 网关地址、Token、与实际安装一致的 EPUSDT / BEpusdt 类型 |
| 支付设置 → 每 5 分钟核对近期付款 | 同时作用于易支付和 USDT 的总开关，按下文检查网关兼容性 |
| 邮件设置 | SMTP 主机、端口、加密方式、账号、发件人和邮件模板；保存后发送测试邮件 |
| 安全设置 | 为正式主机名配置 Turnstile 的 Site Key / Secret Key |
| Telegram 通知 | 自行创建 Bot，填入 Bot Token 和目标 Chat ID 后启用 |
| SEO 设置 | 默认 SEO、正式站点网址、百度 Token、IndexNow 密钥，按需要启用推送 |

支付网关单独准备，商城不会自动部署易支付、EPUSDT 或 BEpusdt。`APP_URL` 必须为网关可访问的正式 HTTPS 地址，核对商户端域名白名单，避免屏蔽支付回调。

EPUSDT 使用网关默认网络；BEpusdt 支持买家选择 TRC20/BEP20/Polygon，人民币计价固定为 CNY。USDT 创建交易要求 HTTPS，只有回环开发地址允许 HTTP。自动对账默认关闭，启用前须确认网关返回的订单、商户、渠道及金额可以核对；不兼容的网关使用签名回调或人工核对。

EPUSDT 选项指传统 **API Token + MD5** 协议，不兼容改用 `pid` / `secret_key` 和 HMAC-SHA256 的 GMWallet 新接口。请核对服务实际协议；BEpusdt 与传统 EPUSDT 的 JSON 数字签名格式也已分别兼容校验。

SMTP 使用 `ssl` 或 `tls` 并保留证书验证；未配置后台 SMTP 主机时可使用部署环境的 `MAIL_*`。不要在生产使用日志或数组邮件传输发送卡密。邮件模板支持 `{{site_name}}`、`{{order_no}}`、`{{product_name}}`、`{{quantity}}`、`{{total_amount}}` / `{{amount}}`、`{{cards}}`。

Turnstile 小组件允许的主机名需包含实际商城域名，再将配对密钥填入后台。配置依据 [Turnstile 主机名说明](https://developers.cloudflare.com/turnstile/additional-configuration/hostname-management/)。

### 可选功能开关

- **退款申请**：默认关闭，管理员在「系统设置 → 订单设置 → 启用退款申请」决定是否启用。关闭后前台和后台均不能创建新申请，已有记录可继续处理；完成退款前须实际打款并登记凭证。
- **每日备份**：默认关闭，在「系统设置 → 自动备份」开启，设置应用时区（Asia/Shanghai，北京时间）下的执行时间和保留策略。
- **主动对账**：默认关闭，在「系统设置 → 支付设置 → 每 5 分钟核对近期付款」启用；该总开关也作用于 USDT，调度器每 5 分钟核对近期付款。
- **单管理员**：系统采用单管理员模式，不设员工或角色分配；密码和双重验证在「账户与密码」管理。

### 上架商品和验收

> **首次安装预置演示目录说明**：全新空库执行 `migrate` 初始化时，系统会自动一次性预置甲骨文云全球 45 区域完整演示目录（6 大区域分类、90 款升级/普通商品、4 篇指南文章及示例加密卡密），方便开箱预览全部前台模板与下单流程。演示卡密均以 `.example.test` 占位且无真实价值，**正式对外收款前请在管理后台替换为真实商品与库存，或按需下架/删除演示数据**；一旦完成首次初始化或库中已存在业务数据，后续升级与重跑迁移均不会覆盖或重新写入演示数据。

1. 按需在后台调整或清理预置演示分类、商品与示例卡密，并维护自己的真实分类、商品说明、价格、购买数量及低库存阈值。
2. 导入合法 UTF-8 的 TXT/CSV 卡密，核对可售数量。文件每个非空行就是一条完整卡密，不解析 CSV 列，也不跳过表头；不要添加标题行。不支持包含 NUL 的输入。
3. 根据需要创建阶梯价格、优惠码或文章内商品卡片。
4. 从正式 HTTPS 域名下一个真实小额订单，完成付款、卡密展示、复制/TXT 下载、邮件收取及查单。
5. 检查后台库存扣减、付款流水、订单状态和通知投递；再验证未付款取消/到期释放库存的流程。
6. 若开启退款，使用测试订单校验申请、审核、人工退款及完成登记；若需要售后换卡，核对新卡交付和历史。

怀疑某张未售卡有问题时，使用“暂停销售”，它会立即退出可售库存和换卡候选，仍参与导入查重。确认可用后再“恢复销售”。已在商城外成交的卡使用“登记线下售出”；该操作不创建商城订单、付款或销售额。只有无订单关联、未被换出的线下已售卡可撤销登记；订单锁定或已交付卡须通过订单流程处理。

技术自检：

```bash
sudo ./scripts/doctor.sh --production --env-file /etc/cardshop/runtime.env
```

生产自检读取外部环境，检查五个运行服务（含 `worker` 内五项后台子进程）、就绪状态与业务健康。退出码 `0` 为通过，`1` 为警告或跳过，`2` 为失败。真实支付到账、外部邮件、代理证书与防火墙仍须按前文用实际环境验收。

## 日常运行

### 进程与监控

```bash
shop ps
shop logs --tail=50 worker
shop exec -T --user www-data app php artisan shop:health --alert
```

`shop:health` 检查工作进程心跳、通知/SEO 失败与积压、连续对账失败、逾期订单、备份和空间。退出码 `0` 正常、`1` 异常；`--alert` 可通过已启用的 Telegram 发送告警，每 15 分钟最多一条。应由独立服务器监控执行，避免只依赖商城自己的调度器。

通知入队与发货在同一事务完成，独立进程每次失败后按 1/5/15/60 分钟重试，最多 5 次。后台「任务中心 → 通知投递」可重试失败任务；重新入队不代表已经送达。修复失败原因后，有权管理员可以确认已观察的历史告警，新的失败仍会提醒。

日常处理入口：

| 事项 | 后台入口 | 处理结果 |
| --- | --- | --- |
| 付款待核对 / 退款待审核或待打款 | 经营概览待办 → 对应订单或退款记录 | 核对流水再处理；批准退款后仍须人工打款 |
| 邮件或 Telegram 失败 | 任务中心 → 通知投递 | 修复渠道配置后重试，观察最终状态 |
| 对账失败 | 任务中心 → 付款对账任务 | 管理员启用自动对账后重试；不会重新创建支付 |
| SEO 失败 | 任务中心 → 搜索引擎推送 | 修复引擎配置后重试；提交成功不等于已收录 |
| 进程异常、素材与备份 | 运维 → 运行健康 / 上传素材 / 备份与恢复 | 告警确认不等于修复；完整备份需要管理员会话 |

任务中心只统一界面入口，通知、SEO、对账进程仍须分别常驻运行。唯一启用管理员可使用全部模块，详细功能见 [后台功能清单](docs/ADMIN-FUNCTIONS.md)。

### 完整备份与校验

管理员在 **运维 → 备份与恢复** 创建、查看进度、校验和下载备份。归档包含数据库、可读取的运行配置、上传文件、私有素材和历史操作归档，保存在 `storage/app/private/shop-backups/`，必须作为含密钥的私有数据保管。外部 keyring 不进入归档，运行环境文件和完整 keyring 也应独立安全备份。

```bash
shop exec -T --user www-data worker php artisan shop:backup --wait
shop exec -T --user www-data app php artisan shop:restore storage/app/private/shop-backups/实际文件.tar.gz --verify-only
```

CLI 不带 `--wait` 时只入队，带 `--wait` 可执行并等待任务，放在 `worker` 后台容器运行以便使用其异机挂载。自动备份默认关闭，启用后的默认计划为应用时区 Asia/Shanghai（北京时间）每日 03:00、保留 14 份 / 30 天；新副本验证成功后才应用保留策略，保留最新成功副本。该计划不随宿主机时区变化。

可选异地备份支持两种方式（可单选或同时启用）：
- **云端对象存储直传（推荐）**：在后台 **系统设置 → 自动备份** 勾选一个或多个云存储目标——支持 **Cloudflare R2**、**AWS S3**（含兼容 S3 协议的 B2/MinIO）、**阿里云 OSS**（原生 `OSS4-HMAC-SHA256`）和 **腾讯云 COS**（`Bucket-AppID` 虚拟主机）——支持单选、多选或全部同时上传，各目标独立容错并校验 `Content-MD5`，提供「测试连接」按钮与密文存储。
- **异机挂载目录复制**：在外部环境文件设置 `BACKUP_SYNC_MOUNT=/你的受控挂载目录`，应用 Compose 配置后，在后台填 `/mnt/shop-backup-sync`。确保容器 UID/GID 33 可写，并自行设置远端保留策略。后台留空且未勾选云端目标时仅保留本地备份。

### 一键恢复与换服务器迁移恢复

#### 1. 换新服务器一行命令自动恢复（推荐）

换新服务器只需要准备两份文件：**① 最新备份包 `shop-YYYYMMDD-HHMMSS-xxx.tar.gz`**（从 R2/S3/OSS/COS 或旧机下载）和 **② 独立离线保存的密钥文件 `shop-keyring.json`**（旧机 `/etc/cardshop/secrets/shop-keyring.json`）。

在新服务器安装 Docker 并拉取对应正式版仓库后，只需执行 **1 行命令**：

```bash
sudo ./scripts/restore.sh /root/shop-你的备份文件.tar.gz --keyring /root/shop-keyring.json
```

脚本会自动串联完成全部 6 个步骤（无需手工敲 `docker cp` / `chown` / `artisan down` / `optimize:clear` / `artisan up`）：
1. 安装 `shop-keyring.json` 到 `/etc/cardshop/secrets/shop-keyring.json`（权限 `640`，属主 `root:33`）；若新机尚无 `/etc/cardshop/runtime.env`，自动从备份包提取 `config.env` 写入（权限 `640`），已存在时默认保留新机环境文件（加 `--overwrite-env` 可显式覆盖）。**切勿在新机重新执行 `secrets:init`**。
2. 启动 `postgres`、`redis`、`app` 并自动切换至维护模式（停用写入进程）。
3. 拷贝备份包至容器受限目录、修正权限并先执行 `--verify-only` 校验清单、SHA-256 与密钥环匹配性。
4. 在单事务中恢复 PostgreSQL 正式库、商品上传素材 `uploads/`、私有归档与随机后台路径 `admin-path`（失败自动回滚）。
5. 清理应用缓存、拉起全部服务（含 `worker` 与 `nginx`）并退出维护模式。
6. 打印恢复后的后台登录路径。支持加 `--dry-run` 先预演将执行的操作，或加 `--yes` 跳过交互确认。

最后在 Cloudflare DNS 将域名 A/AAAA 记录指向新服务器 IP 并核验后台登录、商品图片与查单功能即可。

#### 2. 管理后台 Web 一键还原（同机回滚 / 上传包 / 云端拉取）

在相同密钥环下（日常回滚、误删商品恢复，或已在后台配置好云端存储的新机），直接在后台 **运维 → 备份与恢复** 即可全程点选完成，无需登录服务器 SSH：

- **本地备份一键还原**：在「完整备份文件」列表中点击目标归档右侧的 **一键还原**。
- **上传本地备份包还原**：点击 **上传备份包** 直接上传 `.tar.gz` 归档（浏览器单次上限 `100 MB`；更大的包请优先走云端对象存储或 `scripts/restore.sh`），上传后立即校验完整性和密钥匹配性，可勾选「上传并校验通过后立即执行全量还原」。
- **云端对象存储拉取与一键还原**：在「云端对象存储备份」卡片选择已启用的 **Cloudflare R2 / AWS S3 / 阿里云 OSS / 腾讯云 COS**，点击 **拉取到本地** 或 **一键拉取并还原**。

所有 Web 还原均要求重新验证当前管理员登录密码；后台会先校验归档与密钥环（不匹配直接拦截、不中断现网），通过后由独立 `worker` 进程（`shop:backup-work`）自动进入维护模式、创建恢复前安全快照、执行单事务全量恢复并自动恢复上线（通常耗时 10–30 秒，页面自动轮询进度）。

#### 3. 恢复演练（独立空库，不覆盖正式库）

如需在不影响线上业务的情况下单独验证归档可恢复性，可创建独立空库进行演练：

```bash
shop exec -T postgres createdb -U cardshop cardshop_restore_check
shop exec -T --user www-data app php artisan shop:restore storage/app/private/shop-backups/实际文件.tar.gz --database=cardshop_restore_check --confirm=restore:cardshop_restore_check
```

> **重要提醒**：独立库演练不修改正在使用的文件和配置。正式库恢复必须在维护模式（`artisan down`）且无写入进程运行时执行；只读生产镜像中的运行环境文件由 `scripts/restore.sh` 或运维离线维护至 `/etc/cardshop/runtime.env`，不能通过 `--include-config` 改写容器只读层。

### 记录与源站维护

「运维 → 上传素材」可以归档未被引用且上传超过 7 天的素材，后续引用可恢复。`php artisan shop:archive-records --days=365` 只预览，显式 `--apply` 后才将旧记录归档、校验并清理；订单、付款回执、退款及未完成任务保留。先完成完整备份再清理。

Cloudflare 网段维护时运行 `sudo ./scripts/cf-only-firewall.sh --apply --yes`，脚本获取当前列表；Nginx 的可信代理网段也需同步并验证。HTTPS 与证书有效期由运维持续监控。停止服务使用 `shop down`，不要加 `-v` 删除业务数据卷。

非 Docker 部署需自行维护 PHP-FPM/Web、PostgreSQL、Redis、外部 keyring，并常驻运行 `php artisan schedule:work`、`php artisan notifications:send --work`、`php artisan seo:work`、`php artisan payments:work` 和 `php artisan shop:backup-work`。

## 常见问题

| 现象 | 核查 |
| --- | --- |
| 页面 500 | 应用日志、数据库连接、`.env` 和 `storage` 的工作进程权限；不要只看启动成功 |
| Cloudflare 521 / 522 | Nginx、80/443 监听、DNS、云安全组及源站防火墙 |
| Cloudflare 526 | 源站证书有效期、主机名、证书链、TLS 配置及 Full (strict) |
| 后台资源缺失 | 确认正式版 `public/admin-assets/`、manifest 和构建标记完整，查看 app 启动日志 |
| 后台不能登录 / 页面资源协议错误 | `APP_URL`、实际访问协议、Cookie 和 `ADMIN_PATH` |
| 人机验证失败 | Turnstile 密钥配对、允许主机名及容器出站连接 |
| 付款后未交付 | 支付流水、回调可达性、服务端金额/渠道校验及待核对回执；先查单，避免重复付款 |
| 邮件未收到 | SMTP TLS、测试邮件、通知任务状态和 `notifications` 进程，垃圾邮件文件夹 |
| 备份等待或失败 | `backups` 进程、磁盘空间、PostgreSQL 客户端、归档权限与挂载目录 |
| 访客 IP 不准确 | 代理链与 Nginx 可信网段；本文方案保持 `TRUSTED_PROXIES` 为空 |
| 源站 IP 可直接打开商城 | 从外部电脑复核防火墙、IPv6 与云安全组 |

诊断命令：

```bash
shop logs --tail=100 app nginx postgres
sudo ./scripts/doctor.sh --production --env-file /etc/cardshop/runtime.env
shop exec -T --user www-data app php artisan shop:health
```

[返回项目介绍](README.md) · [API 文档](docs/API.md) · [模板开发](docs/THEMES.md)
