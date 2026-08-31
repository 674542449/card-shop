# 全新服务器部署

从一台空的 Ubuntu 服务器和一个新域名开始，到店铺可以正常收单。

本文假设你**用 Cloudflare 做 CDN**——整套方案（真实访客 IP 的还原、源站证书、只放行
Cloudflare 的防火墙）都建立在这个前提上。

按顺序做，别跳步。每一步后面都有「怎么确认成功」，请真的执行它再往下走：这个项目
出过一次全站 500 而启动日志一片绿的事故，**能骗过你的从来不是报错，是没有报错**。

---

## 开始前准备

| 需要什么 | 说明 |
|---|---|
| 一台服务器 | Ubuntu 22.04+ / Debian 12+。最低 1 核 1G，推荐 2 核 2G 起。磁盘 20G 以上 |
| 一个域名 | 已经加进 Cloudflare 账号（域名在哪注册的都行，NS 指到 Cloudflare 即可） |
| SSH 能登进服务器 | 后面所有命令都在服务器上跑 |

云厂商（Oracle / AWS / 腾讯云等）的**安全组或安全列表要放行 80、443**。这是独立于服务器
本身防火墙的一层，不放行的话 Cloudflare 回不了源，站点会 522。

> Oracle Cloud 特别注意：VCN 安全列表和 NSG 是**并集**关系（任一放行即放行），只改一处
> 可能不生效，两处都要看。

---

## 关于「你的域名」怎么填

全文用 **`shop.example.com`** 做示例，你照着替换成自己的即可。

有两种写法，**用错了会静默失败或者直接报错**，所以单列一节：

| 场景 | 格式 | 示例 |
|---|---|---|
| `./install.sh` 问你域名 | **不带协议** | `shop.example.com` |
| 防火墙脚本 `--domain=` | **不带协议** | `--domain=shop.example.com` |
| Cloudflare 签源站证书的主机名 | **不带协议** | `shop.example.com` 和 `*.shop.example.com` |
| Cloudflare 面板里 Turnstile 的域名 | **不带协议** | `shop.example.com` |
| `curl` 验证命令 | **带 `https://`** | `curl -s https://shop.example.com/` |
| 防火墙脚本 | **不用填**，自动从 `.env` 读 | （只在想覆盖时才 `--domain=`） |
| `.env` 里的 `APP_URL` | **带 `https://`** | `APP_URL=https://shop.example.com` |
| 浏览器地址栏 | **带 `https://`** | `https://shop.example.com/admin` |

一句话记法：**命令行参数和面板输入框里填裸主机名，URL 里才带 `https://`。**

（`install.sh` 有容错，你贴 `https://shop.example.com/` 进去它也会自动去掉协议和结尾
斜杠。但防火墙脚本的 `--domain=` **没有**这个容错，带协议会解析失败。）

### 带不带 www，要一致

这是个真的会绊人的地方。如果你在 Cloudflare 里只加了 `www` 这一条 A 记录，那么：

- ✅ `www.shop.example.com` 能访问
- ❌ `shop.example.com` 解析不到，浏览器直接打不开

而防火墙脚本的第一件事就是解析你给的域名、确认它落在 Cloudflare 网段里 —— 域名解析
不了它会直接拒绝执行，报「解析不到任何 IP」。

**所以：本文所有出现域名的地方，都要填你实际能访问的那一个。** 只加了 `www` 记录就
一律用 `www.shop.example.com`，包括 `--domain=`、`APP_URL`、证书主机名。

更省事的做法是在 Cloudflare 的 DNS 里把两个都加上，橙云都开：

| 类型 | 名称 | 内容 | 代理 |
|---|---|---|---|
| `A` | `@` | 服务器公网 IP | 已代理 |
| `CNAME` | `www` | `shop.example.com` | 已代理 |

这样带不带 `www` 都能访问，后面每一步就不用再纠结了。

---

## 第 1 步：域名接入 Cloudflare

这一步必须在最前面。不先做的话，后面每一步都没法验证——容器全起来了、日志全绿、
管理员密码也拿到了，然后域名打不开，你会去查 nginx、查防火墙、查配置，而真正缺的
只是一条 DNS 记录。

在 Cloudflare 面板：

1. **DNS** → 添加记录。建议两条都加，省得后面每一步都要纠结带不带 `www`：

   | 类型 | 名称 | 内容 | 代理状态 |
   |---|---|---|---|
   | `A` | `@` | 服务器公网 IP，如 `129.146.48.95` | **已代理**（橙色云朵） |
   | `CNAME` | `www` | `shop.example.com` | **已代理**（橙色云朵） |

2. 代理状态必须是**橙色云朵**，不能是「仅限 DNS」（灰色）
3. **SSL/TLS** → 概述 → 加密模式暂时选 **灵活 (Flexible)**（第 5 步做完再改成 Full strict）

**橙云一定要开。** 不开的话 nginx 里那 22 条 `set_real_ip_from` 永远匹配不上，真实访客 IP
的还原会**静默失效**——不报错，只是所有访客的 IP 都变成同一个，于是按 IP 的限流、
「每 IP 最多 3 笔未支付订单」、IP 黑名单全部退化成全站共用一个桶。同时你的源站 IP 也会
直接暴露在 DNS 里。

**怎么确认成功**（在你自己电脑上跑，不是服务器）：

```bash
curl -sI https://shop.example.com/ | grep -i "cf-ray\|server:"
```

出现 `cf-ray` 这一行就说明流量确实经过 Cloudflare 了。此时站点还没部署，返回 5xx 是正常的，
我们只看有没有 `cf-ray`。

---

## 第 2 步：装 Docker

```bash
curl -fsSL https://get.docker.com | sh
```

**怎么确认成功**：

```bash
docker --version && docker compose version
```

两行都要有输出。`docker compose version` 报错说明只装了老版的 `docker-compose`，
本项目需要 v2 插件版。

---

## 第 3 步：拉代码，跑安装脚本

```bash
git clone https://github.com/674542449/card-shop.git ~/card-shop
cd ~/card-shop
./install.sh
```

脚本会做两件事：

1. **问你域名**——填 `shop.example.com` 这样的**裸主机名**，不用带 `https://`
   （贴了也没关系，脚本会自动去掉）。它会写进 `.env` 的 `APP_URL`。
   这个值决定会话 cookie 的 `Secure` 标志、资源链接的协议、canonical、以及发给买家的
   订单链接。填错的典型症状是**后台登不进去**，或者 HTTPS 页面加载不到样式。
2. **按你这台机器的 CPU 和内存算出 PHP-FPM 进程数**并让你选。镜像默认只有 5 个工作
   进程——意思是同时 5 个请求就占满了，跟你机器多大没关系。选推荐档即可。

它还会自动生成一个随机的数据库密码（默认值 `secret` 是不能用在生产上的）。

**怎么确认成功**：

```bash
grep -E '^(APP_URL|PHP_FPM_MAX_CHILDREN|TRUSTED_PROXIES)=' .env
```

应该看到这样三行（域名换成你自己的）：

```
APP_URL=https://shop.example.com
PHP_FPM_MAX_CHILDREN=20
TRUSTED_PROXIES=
```

三点都要对上：

- **`APP_URL` 带 `https://`，且是你实际能访问的那个主机名**（只加了 `www` 记录就要写
  `https://www.shop.example.com`）。这里写错，症状是后台登不进去或者页面没样式。
- **`PHP_FPM_MAX_CHILDREN` 有值**，不是空。空的话容器会退回镜像默认的 5 个进程。
- **`TRUSTED_PROXIES` 是空的**——真实 IP 由 nginx 还原，这里填任何值（尤其是 `*`）反而会
  让任何人直连源站伪造 `X-Forwarded-For`，绕过全部按 IP 的防护。

---

## 第 4 步：起容器

```bash
docker compose up -d --build
```

首次构建要下载镜像并在容器里安装 PHP 依赖，**慢的机器上可能要 5–10 分钟**。
中途 `docker compose up` 如果报 `app is unhealthy` 就退出了，多半是依赖装得比预算慢，
再执行一次 `docker compose up -d` 即可。

看进度：

```bash
docker compose logs -f app
```

**怎么确认成功**——三条都要过：

```bash
# ① 五个容器都在跑
docker compose ps

# ② .env 权限正确（这条很关键，见下方说明）
docker compose exec app su -s /bin/sh -c 'test -r /var/www/html/.env && echo readable || echo NOT-readable' www-data

# ③ 站点真的渲染出内容了（不要只看状态码）
curl -s http://127.0.0.1/ | grep -c "csrf-token"
```

- ① 应该看到 `app` `scheduler` `nginx` `postgres` `redis` 五个，状态 `Up`
- ② **必须输出 `readable`**。PHP-FPM 的工作进程以 `www-data` 运行，读不到 `.env` 就会
  全站 500，而启动日志会一片绿（entrypoint 里的迁移和种子是 root 跑的，照样成功）。
  这个坑真实发生过，非常难自己诊断。输出 `NOT-readable` 的话执行
  `sudo chgrp 33 .env && sudo chmod 640 .env && docker compose restart app` 再验一次。
  两个 `sudo` 都不能省：把文件改成一个自己不是成员的组需要 root，普通用户即使是属主也会
  被拒。用 `restart app` 而不是 `up -d`：compose 配置没变时 `up -d` 不会重建容器，
  entrypoint 里那道权限修复和验证根本不会重跑。
- ③ **输出必须大于 0**。只看 HTTP 状态码会被骗——Laravel 的 500 错误页也是一个完整的
  HTML 页面，`curl -o /dev/null -w '%{http_code}'` 分不出它和正常页面。
  用 `csrf-token` 做标记是因为它在应用的布局模板里，而框架的错误页没有它：能抓到它，
  就说明应用真的把页面渲染出来了，而不是抛异常抛出来一张错误页。

### 管理员密码

**推荐做法：起容器之前就自己定好。** 在第 3 步之后、第 4 步之前，往 `.env` 里加一行
（至少 12 位）：

```bash
echo "ADMIN_PASSWORD=你自己定的密码" >> ~/card-shop/.env
```

这样就不用去日志里翻，也不会错过。没设的话，首次启动会生成一个随机密码，
**只在日志里打印一次**：

```bash
docker compose logs app | grep -A6 "管理员账号已创建"
```

错过了也不要紧，有专门的命令可以重置（**不要用 tinker，生产镜像里没装**）：

```bash
# 指定密码（至少 12 位）
docker compose exec app php artisan admin:password --password='你的新密码'

# 或者让它随机生成一个并打印出来
docker compose exec app php artisan admin:password
```

---

## 第 5 步：源站证书（回源加密）

不做这一步，Cloudflare 的加密模式只能停在「灵活」，**CF 到你服务器这一段是明文**——
而这一段跑的是管理员会话 cookie 和发给买家的卡密。

**三步按顺序做，不要整块复制粘贴。** 证书还没放进去就先建 `ssl.conf` 的话，nginx 会因为
找不到证书文件而**完全起不来**——连原本正常的 80 端口一起没了，站点从「能访问」变成
「彻底下线」，而报错指向证书，很容易误判。

**① 签证书**

Cloudflare 面板 → **SSL/TLS** → **源服务器 (Origin Server)** → **创建证书**。
主机名填**裸主机名**，两行：`shop.example.com` 和 `*.shop.example.com`（不带 `https://`）。
有效期选 15 年。签完会给你两段文本。

**② 放到服务器**

```bash
mkdir -p /opt/cf
```

然后把两段内容分别粘进去（用 `nano`，粘完 Ctrl+O 保存、Ctrl+X 退出）：

```bash
nano /opt/cf/cert.pem    # 粘贴「源证书」那一段
nano /opt/cf/key.pem     # 粘贴「私钥」那一段
```

收紧私钥权限：

```bash
chmod 600 /opt/cf/key.pem
```

**③ 启用 443 监听**

不要手工 `cp` 配置文件，重跑安装脚本即可——它只在**确认两个证书文件都在**时才创建
`ssl.conf`，所以不存在「配置建好了但证书还没到位」这个能把站点搞挂的中间状态：

```bash
cd ~/card-shop && ./install.sh --recommended && docker compose up -d --force-recreate nginx
```

**必须带 `--force-recreate nginx`。** `ssl.conf` 是通过 bind mount 进容器的，新建这个文件
容器里立刻就能看到——但 nginx 只在启动时读一次配置，不重建容器它就一直用着旧配置，
443 永远起不来。而下一步把 Cloudflare 改成 Full (strict) 之后，CF 连不上 443，
站点直接 521 下线。`docker compose up -d`（不带 --force-recreate）在 compose 配置没变时
不会重建任何容器，只会输出一行 Running。

**④ 回 Cloudflare 面板**，把加密模式从「灵活」改成 **完全（严格）/ Full (strict)**，
并开启 **始终使用 HTTPS** 和 **HSTS**。

**怎么确认成功**：

```bash
# 源站自己在 443 上能握手（-k 是因为 CF 源站证书不被公共 CA 信任，这是正常的）
curl -sk https://127.0.0.1/ | grep -c "csrf-token"

# 从外网经 Cloudflare 访问
curl -s https://shop.example.com/ | grep -c "csrf-token"
```

两个都要大于 0。

---

## 第 6 步：只让 Cloudflare 连得上源站

`docker-compose.yml` 把 80/443 发布在 `0.0.0.0`，而 **Docker 是直接往 nat 表写规则的，
`ufw` 完全拦不住**。不做这一步，任何人拿到你的源站 IP 就能绕开 Cloudflare 的 WAF、
限速和 Bot 防护直连，CDN 等于白挂。

先体检（只检查，不改任何规则）：

```bash
cd ~/card-shop
sudo ./scripts/cf-only-firewall.sh --check
```

**不用填域名**——脚本自己从 `.env` 的 `APP_URL` 读（就是第 3 步 `install.sh` 写进去的
那个），并把读到的值打印出来给你确认。要用别的域名才需要 `--domain=shop.example.com`
（裸主机名，不带 `https://`）。

它会确认：域名确实解析到 Cloudflare 网段、经 CF 能正常访问、外网网卡识别正确、
端口映射一致、有没有 IPv6 旁路。**任何一项不满足就会拒绝执行**——在没挂 CDN 的机器上
应用这套规则等于把站点对所有人封死，而规则藏在 `DOCKER-USER` 链里，`ufw status` 是空的、
`docker ps` 是正常的，几乎不可能自己定位。

体检通过后应用：

```bash
sudo ./scripts/cf-only-firewall.sh --apply --persist
```

`--persist` 会装一个开机自启单元，否则重启后规则就没了（iptables 规则不持久，而失效时
站点访问一切正常，你不会察觉，源站却重新对全网敞开）。

它装完会**立刻执行一次**这个单元，所以你当场就知道开机时能不能跑通，不用等到下次重启。
成功的话输出里会有「开机自启已安装并**当场执行成功**」。

脚本只操作 `DOCKER-USER` 链，从不触碰 `INPUT`，**不会影响 SSH**。

**怎么确认成功**——两个方向都要验，这点很重要：

```bash
# ① 容器出站还通（这条最容易被忽略）
docker compose exec app curl -m 10 -s -o /dev/null -w '%{http_code}\n' https://api.github.com

# ② 经 Cloudflare 入站正常
curl -s https://shop.example.com/ | grep -c "csrf-token"
```

① 应该是 2xx/3xx，② 应该大于 0。

**为什么必须验出站**：`DOCKER-USER` 挂在 `FORWARD` 链上，容器访问外网也经过它。规则写得
不对会把容器的出站流量一起丢掉，表现是 **USDT 支付跳转不过去、Turnstile 验证失败、
Telegram 通知和邮件推送全部中断**，而入站看起来一切正常——几乎没人会联想到防火墙。
这个坑真实发生过。

最后，从**你自己电脑**（不是服务器）确认源站直连已经被封死：

```bash
curl -m 10 http://服务器IP/ ; echo "退出码 $?"
```

应该超时（退出码 28），而不是返回页面。

出问题随时回滚：

```bash
sudo ./scripts/cf-only-firewall.sh --remove
```

---

## 第 7 步：后台配置

打开 `https://shop.example.com/admin`，用第 4 步拿到的密码登录。

### 把后台从 /admin 挪走（可选，建议做）

`/admin` 是全世界扫描器的第一个猜测。改掉它：

```bash
cd ~/card-shop
echo "ADMIN_PATH=你起的名字" >> .env      # 只能用字母数字和 - _，最长 32 位
docker compose up -d app
```

改完 `/admin` 直接 404，后台和它的 API 一起搬到新路径。前端是运行时读这个值的，
**不用重新构建 SPA**。

**这是「安全靠隐藏」，不是访问控制。** 它挡住的是满世界扫 `/admin`、`/wp-admin` 的
机器人——你的日志会干净非常多——但挡不住有针对性的攻击者。真正保护后台的是强密码、
登录限流、Turnstile 和 IP 黑名单。别因为改了路径就放松那几样。

用户名和密码也可以改，而且更重要：

```bash
# 改密码（至少 12 位）
docker compose exec app php artisan admin:password --password='新密码'

# 用户名要在首次部署前设，写进 .env 的 ADMIN_USERNAME=你的用户名
```

后台菜单：概览 / 商品 / 交易 / 内容 / 系统。下面这些都在**系统 → 系统设置**这一页里，
分卡片排列。

| 配置项 | 不配的后果 |
|---|---|
| **修改密码**（页面底部那张卡片） | 随机密码在日志里，而日志有 10MB × 3 的轮转上限，迟早被冲掉 |
| **站点名称、SEO** | 标题栏和搜索结果里显示的是默认值 |
| **支付网关**（易支付 / USDT） | 收不了款 |
| **SMTP 邮件** | 买家收不到卡密邮件。填完用同一张卡片上的「发送测试邮件」验证 |
| **Turnstile 人机验证** | 不配等于下单和订单查询没有任何验证，刷单和撞库畅通 |

### Turnstile 要为新域名单独建

这对 key 在 Cloudflare 侧**绑定 hostname**。如果你从旧站抄 key 过来，新域名不在那个组件的
主机名列表里，`siteverify` 会一律返回失败，于是**下单和订单查询全部被拒**，提示「人机验证
失败，请重试」——而前台那个验证组件是正常渲染、能划过的，看起来完全不像配置问题。

正确做法：Cloudflare 面板 → **Turnstile** → 添加小组件 → 域名填**新域名** → 把新的
Site Key 和 Secret Key 填进后台。

### 支付回调：别用 IP 下测试单

支付回调地址是用 `url()` 拼的，Laravel 的 `url()` 取的是**当前请求的 Host**，不是 `APP_URL`。
你用 `http://服务器IP/` 下一笔测试单，回调地址就被写成 `https://服务器IP/payment/...`
发给支付网关——网关回调打不进来，**订单永远停在未支付**，而下单流程本身一切正常。

**所有测试都走 `https://shop.example.com/`。**

---

## 第 8 步：一键自检

前面每一步都有各自的验证命令。这一步把它们全部合并成一条，自动跑一遍：

```bash
cd ~/card-shop && sudo ./scripts/doctor.sh
```

**域名不用填**——它从 `.env` 的 `APP_URL` 读。

它会检查：`.env` 的每一项配置、文件权限（包括容器里的 www-data 能不能读到）、五个容器
是否在跑、scheduler 的运行身份、本地和经 Cloudflare 的页面渲染、CF-RAY、HSTS、
HTTP 跳转、源站证书、容器出站、防火墙链的结构与可达性、开机自启单元。

输出分四类，**跳过的项也会计数并列出来**——「没检查」和「没问题」是两回事：

```
通过 24   警告 1   失败 0   跳过 0
```

退出码：`0` 全通过 / `1` 有警告或跳过项 / `2` 有失败项。

### 能自动修的，加 --fix

```bash
sudo ./scripts/doctor.sh --fix
```

它只修有把握的几项：`.env` 的权限和属组、被误设成 `*` 的 `TRUSTED_PROXIES`、
证书就位但没启用的 443、以 root 身份跑的 scheduler、没装的防火墙规则。
每修一项都会打印出来。改不动的会告诉你手工命令，不会假装成功。

### 有一项它测不了

**源站直连**必须从**外部**验证。服务器连自己的公网 IP 不经过 `DOCKER-USER` 所在的
`FORWARD` 链，在本机测必然是「能连上」，是假阳性。脚本会把命令打印给你，在**你自己
电脑**上跑：

```bash
curl -m 10 http://你的服务器IP/ ; echo "退出码 $?"
```

应该超时（退出码 28）。返回了页面就说明源站还能被绕过 Cloudflare 直连。

### 最后一件事

**下一笔真实小额订单**走完整个流程：下单 → 支付 → 收到卡密 → 用订单号和查询密码在前台
查回来。这是唯一能同时证明支付回调、自动发货、邮件三条链都通的办法。记得走
`https://shop.example.com/`，不要用 IP（原因见第 7 步）。

---

## 迁移旧站数据（可选）

只在你要保留旧站的订单和卡密时才做。

**旧**服务器上导出：

```bash
cd ~/card-shop
docker compose exec -T postgres pg_dump -U cardshop --clean --if-exists cardshop | gzip > backup.sql.gz
```

传到新服务器后导入（新站容器要先起来，让它建好库结构）：

```bash
gunzip -c backup.sql.gz | docker compose exec -T postgres psql -U cardshop -d cardshop -v ON_ERROR_STOP=1
```

两个参数都不能省。`--clean --if-exists` 让导出的脚本先删掉同名的表再建，否则导进一个
已经跑过迁移、表都在的库只会一路报「已存在」；`-v ON_ERROR_STOP=1` 让 psql 一遇错就停并
返回非零，**默认它会跳过错误继续跑完然后返回 0**，于是你以为导成功了，实际只导进去一半。

上传的图片在 `storage/app/public/uploads/`，一并 `rsync` 过去。

**导完必须回到第 7 步**：库里存着一批跟域名绑定的设置（Turnstile key、支付回调白名单、
SEO 里可能写死的旧域名），直接搬库过来它们全是旧值。

旧站先别关。等新站跑通一整天、确认订单和支付都正常，再下线。

---

## 日常维护

### 升级

```bash
cd ~/card-shop
git status --porcelain                    # ① 必须为空，否则 git pull 会中途 abort
git rev-parse HEAD > /tmp/rollback.txt    # ② 记下当前版本，出事好退回
git pull

docker compose up -d                      # ③ 让改过的 compose 配置生效（没改就是空操作）
docker compose restart app                # ④ 这条不能省，理由见下
docker compose restart nginx              # ⑤ 避免 502
```

**① `git status --porcelain` 必须为空。** `composer.lock` 是被跟踪的文件，而 entrypoint
在 `composer install` 失败时会兜底跑 `composer update`（会改写它）。一旦它变脏，`git pull`
直接 abort；如果你把命令用 `&&` 串成一行，链子就断在这里，你只看到一行报错，很容易以为
整条跑完了。所以这几条**分开执行**，不要串成一行。

**④ `restart app` 不能省，而且不能用 `up -d --build` 代替它。** compose 只在「镜像 ID 变了」
或「服务配置变了」时才重建容器。只改了 PHP 代码和模板时，`--build` 的每一层都命中缓存，
镜像 ID 不变，于是 `up -d --build` 打印一行 `Running` 就结束，**app 容器一秒都没停**。
后果全是静默的：entrypoint 里的 `migrate`、`db:seed`、`view:cache`，以及那道「编译产物能不能
被 PHP 解析」的预检，一条都不会跑；命令返回 0，日志里什么都没有。
（同样的道理见第 4 步那条注记：配置没变时 `up -d` 不会重建容器。）

**什么时候需要 `--build`：** 只有 `docker/` 目录或 `composer.json` 变过时才需要，此时把 ③ 换成
`docker compose up -d --build`。判断方法：
```bash
git diff --name-only HEAD@{1} HEAD -- docker/ composer.json
```
有输出才加 `--build`。特别注意 `docker/php/entrypoint.sh`：它是构建时 COPY 进镜像的，
容器执行的是镜像里那一份，**光 restart 不会生效，必须 build**。

**⑤ `restart nginx`** 是因为 nginx 启动时就把 `app:9000` 解析成了一个固定 IP。app 容器一旦
被重建就会换 IP，而 nginx 还握着旧地址 —— 表现是升级后整站 502，而 `docker compose ps` 里
五个容器全是 Up。

升级后按第 4 步的三条验证跑一遍，**再加下面这两条**（第 4 步只能证明「站还活着」，证明不了
「更新到位了」）：

```bash
# 页面里的资源版本号，必须等于容器里文件的真实 mtime；对不上说明你拿到的是缓存的旧页面
curl -s https://你的域名/ | grep -oE '(css/front\.css|js/front\.js)\?v=[0-9]+'
docker compose exec -T app php -r 'foreach(["public/css/front.css","public/js/front.js"] as $p) echo $p, " ?v=", @filemtime($p), PHP_EOL;'

# entrypoint 真的重跑了：这个时间必须是刚才
docker inspect -f '{{.State.StartedAt}}' cardshop-app
```

**新拉下来的文件权限。** `git pull` 落地的新文件，模式由你执行 git 时的 umask 决定。
nginx（读 `public/`）和 PHP-FPM 的 www-data（读 `resources/`）都只靠 `o+r`。umask 若是 077，
症状是新模板整站 500、样式表 403，而启动日志和 `doctor.sh` 全绿 —— 和 `.env` 那个坑同一类。
```bash
docker compose exec -T app su -s /bin/sh -c 'head -c 1 public/index.php >/dev/null && echo OK || echo UNREADABLE' www-data
# 输出 UNREADABLE 就执行：sudo chmod -R a+rX public resources
```

**回滚：**
```bash
git reset --hard $(cat /tmp/rollback.txt) && docker compose restart app nginx
```

### 备份

数据全部在 `pgdata` 这个 Docker 卷里，机器没了就没了。建议配个每日备份：

```bash
mkdir -p /opt/cardshop-backups
cat > /etc/cron.daily/cardshop-backup <<'EOF'
#!/bin/sh
cd /root/card-shop || exit 1
docker compose exec -T postgres pg_dump -U cardshop --clean --if-exists cardshop \
  | gzip > /opt/cardshop-backups/db-$(date +%F).sql.gz
find /opt/cardshop-backups -name 'db-*.sql.gz' -mtime +14 -delete
EOF
chmod +x /etc/cron.daily/cardshop-backup
```

先手动跑一次确认能生成文件：`/etc/cron.daily/cardshop-backup && ls -lh /opt/cardshop-backups/`

### Cloudflare 网段会变

一年会变几次。漏同步的后果是新网段的访客被防火墙丢掉——站点对一部分地区不可达，
而日志里什么都看不到。建议每月跑一次：

```bash
cd ~/card-shop && sudo ./scripts/cf-only-firewall.sh --apply --yes
```

（`docker/nginx/default.conf` 里那份列表需要手工同步，文件里标注了抓取日期。）

---

## 排查

| 症状 | 先查什么 |
|---|---|
| 全站 500，但启动日志全绿 | `.env` 权限。跑第 4 步的验证 ②，必须是 `readable` |
| 站点 522 | 云厂商安全组没放行 80/443，或防火墙规则把 Cloudflare 也挡了 |
| 站点 521 | 源站 nginx 没起来。`docker compose logs nginx`，多半是证书路径不对 |
| HTTPS 页面没样式 | `APP_URL` 不是 `https://` 开头 |
| 后台登不进去（密码是对的） | 同上，`APP_URL` 决定会话 cookie 的 `Secure` 标志 |
| 下单提示「人机验证失败」 | Turnstile 的 key 是别的域名的，见第 7 步 |
| 订单一直停在未支付 | 用 IP 下的单，回调地址是错的。改用域名重下一单 |
| USDT 跳转不过去 / 通知不发 | 容器出站被切了。跑第 6 步的验证 ① |
| 访客 IP 都一样 | Cloudflare 的橙云没开 |
| 防火墙脚本报「解析不到任何 IP」 | `--domain=` 填的主机名没有 DNS 记录。只加了 `www` 记录就要写 `www.shop.example.com`，别写裸域 |
| 防火墙脚本报「不在 Cloudflare 网段内」 | 那条 DNS 记录的代理状态是灰云，不是橙云 |
| 直连服务器 IP 还能打开站点 | 第 6 步没做，或者做了但没生效。从**外网**（不是服务器本机）测才准 |

看日志：

```bash
docker compose logs -f app        # 应用
docker compose logs -f nginx      # 网关
docker compose logs -f postgres   # 数据库
```

日志已配了 10MB × 3 的上限，不会撑爆磁盘。
