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

## 第 1 步：域名接入 Cloudflare

这一步必须在最前面。不先做的话，后面每一步都没法验证——容器全起来了、日志全绿、
管理员密码也拿到了，然后域名打不开，你会去查 nginx、查防火墙、查配置，而真正缺的
只是一条 DNS 记录。

在 Cloudflare 面板：

1. **DNS** → 添加一条 `A` 记录，名称填 `@`（或你要用的子域名），内容填**服务器公网 IP**
2. 代理状态必须是 **已代理**（橙色云朵），不能是「仅限 DNS」（灰色）
3. **SSL/TLS** → 概述 → 加密模式暂时选 **灵活 (Flexible)**（第 5 步做完再改成 Full strict）

**橙云一定要开。** 不开的话 nginx 里那 22 条 `set_real_ip_from` 永远匹配不上，真实访客 IP
的还原会**静默失效**——不报错，只是所有访客的 IP 都变成同一个，于是按 IP 的限流、
「每 IP 最多 3 笔未支付订单」、IP 黑名单全部退化成全站共用一个桶。同时你的源站 IP 也会
直接暴露在 DNS 里。

**怎么确认成功**（在你自己电脑上跑，不是服务器）：

```bash
curl -sI https://你的域名/ | grep -i "cf-ray\|server:"
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

1. **问你域名**——填新域名即可，不用带 `https://`。它会写进 `.env` 的 `APP_URL`。
   这个值决定会话 cookie 的 `Secure` 标志、资源链接的协议、canonical、以及发给买家的
   订单链接。填错的典型症状是**后台登不进去**，或者 HTTPS 页面加载不到样式。
2. **按你这台机器的 CPU 和内存算出 PHP-FPM 进程数**并让你选。镜像默认只有 5 个工作
   进程——意思是同时 5 个请求就占满了，跟你机器多大没关系。选推荐档即可。

它还会自动生成一个随机的数据库密码（默认值 `secret` 是不能用在生产上的）。

**怎么确认成功**：

```bash
grep -E '^(APP_URL|PHP_FPM_MAX_CHILDREN|TRUSTED_PROXIES)=' .env
```

预期：`APP_URL` 是你的新域名且以 `https://` 开头；`PHP_FPM_MAX_CHILDREN` 有值（不是空）；
`TRUSTED_PROXIES` **是空的**——真实 IP 由 nginx 还原，这里填任何值（尤其是 `*`）反而会让
任何人直连源站伪造 `X-Forwarded-For` 绕过全部按 IP 的防护。

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
主机名填 `你的域名` 和 `*.你的域名`，有效期选 15 年。签完会给你两段文本。

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
curl -s https://你的域名/ | grep -c "csrf-token"
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
sudo ./scripts/cf-only-firewall.sh --check --domain=你的域名
```

它会确认：域名确实解析到 Cloudflare 网段、经 CF 能正常访问、外网网卡识别正确、
端口映射一致、有没有 IPv6 旁路。**任何一项不满足就会拒绝执行**——在没挂 CDN 的机器上
应用这套规则等于把站点对所有人封死，而规则藏在 `DOCKER-USER` 链里，`ufw status` 是空的、
`docker ps` 是正常的，几乎不可能自己定位。

体检通过后应用：

```bash
sudo ./scripts/cf-only-firewall.sh --apply --domain=你的域名 --persist
```

`--persist` 会装一个开机自启单元，否则重启后规则就没了（iptables 规则不持久，而失效时
站点访问一切正常，你不会察觉，源站却重新对全网敞开）。

脚本只操作 `DOCKER-USER` 链，从不触碰 `INPUT`，**不会影响 SSH**。

**怎么确认成功**——两个方向都要验，这点很重要：

```bash
# ① 容器出站还通（这条最容易被忽略）
docker compose exec app curl -m 10 -s -o /dev/null -w '%{http_code}\n' https://api.github.com

# ② 经 Cloudflare 入站正常
curl -s https://你的域名/ | grep -c "csrf-token"
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

打开 `https://你的域名/admin`，用第 4 步拿到的密码登录。

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

**所有测试都走 `https://你的域名/`。**

---

## 第 8 步：上线前最终核对

```bash
cd ~/card-shop && ./install.sh --show
```

这条是只读的，不会改任何文件。它会列出 `.env` 里还有什么该改（`APP_DEBUG`、`APP_ENV`、
`APP_URL`、HTTPS 配置），以及脚本做不到、需要你人工完成的事项。

理想输出是「未发现问题」。

再手工过一遍这几条：

```bash
# 前台、订单查询、后台都能出内容（三个数字都要大于 0）
for p in "/" "/order/query" "/admin"; do
  printf "%-14s %s\n" "$p" "$(curl -s https://你的域名$p | grep -c csrf-token)"
done

# .env 权限
ls -l ~/card-shop/.env        # 应该是 -rw-r----- root www-data

# 防火墙开机自启装好了
systemctl is-enabled cf-only-firewall
```

然后**下一笔真实小额订单**走完整个流程：下单 → 支付 → 收到卡密 → 用订单号和查询密码
在前台查回来。这是唯一能证明支付回调、发货、邮件三条链都通的办法。

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
cd ~/card-shop && git pull && docker compose up -d --build && docker compose restart nginx
```

用 `up -d` 而不是 `restart`：`restart` 是拿旧配置和旧挂载重启容器，改过 `docker-compose.yml`
或 `TLS_CERT_DIR` 的话不会生效。

末尾那条 `restart nginx` 是因为 nginx 在启动时就把 `app:9000` 解析成了一个固定 IP。
`up -d --build` 重建了 app 容器，它会拿到新 IP，而 nginx 不在重建之列，还握着旧地址 ——
表现是升级后整站 502，而 `docker compose ps` 里五个容器全是 Up。

升级后按第 4 步的三条验证跑一遍。

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
cd ~/card-shop && sudo ./scripts/cf-only-firewall.sh --apply --domain=你的域名 --yes
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

看日志：

```bash
docker compose logs -f app        # 应用
docker compose logs -f nginx      # 网关
docker compose logs -f postgres   # 数据库
```

日志已配了 10MB × 3 的上限，不会撑爆磁盘。
