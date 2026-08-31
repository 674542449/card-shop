#!/usr/bin/env bash
#
# 一键自检：把 DEPLOY.md 里散落在各步骤的验证命令合并成一条，全部自动跑一遍。
#
# 域名不用填，从 .env 的 APP_URL 读——部署过程里域名要填好几处，每多一处手填就多一次
# 填错或填不一致的机会，而这类错误的表现往往不指向真正的原因（比如少个 www，防火墙
# 脚本报的是「解析不到任何 IP」）。
#
# 用法：
#   sudo ./scripts/doctor.sh          只检查，不改任何东西
#   sudo ./scripts/doctor.sh --fix    检查，并修复能安全自动修的项
#   sudo ./scripts/doctor.sh --quiet  只输出有问题的项
#
# 退出码：0 = 全部通过；1 = 有警告或跳过项；2 = 有失败项。
#
# 两条贯穿全文的原则，都是这个项目用故障换来的：
#   · 不用 set -e。体检的价值是把问题一次列全，而不是撞到第一个就退出。
#   · 「没检查」必须和「没问题」区分开。跳过的项单独计数、单独列出、计入退出码。

FIX=0
QUIET=0
for a in "$@"; do
    case "$a" in
        --fix)   FIX=1 ;;
        --quiet) QUIET=1 ;;
        -h|--help) sed -n '2,20p' "$0" | sed 's/^#\{1,\} \{0,1\}//'; exit 0 ;;
        *) echo "未知参数：$a（可用 --fix / --quiet / --help）"; exit 1 ;;
    esac
done

RED=$'\033[31m'; GRN=$'\033[32m'; YEL=$'\033[33m'; DIM=$'\033[2m'; RST=$'\033[0m'
PASS=0; WARN=0; FAIL=0; SKIP=0
FIXED=""

# 一律用 echo 而不是带 \n 的 printf：printf 的格式串里一旦混进字面换行，以后整段缩进
# 时收尾的缩进就会被吃进格式串。这个坑在 install.sh 里踩过一次。
# 三个函数都显式 return 0 —— 它们被用在 `A && pass || fail` 链里，返回非零会让 ||
# 分支跟着一起执行。
pass() { PASS=$((PASS+1)); [ "$QUIET" = "1" ] || echo "  ${GRN}+${RST} $1"; return 0; }
warn() { WARN=$((WARN+1)); echo "  ${YEL}!${RST} $1"; [ -n "${2:-}" ] && echo "      ${DIM}${2}${RST}"; return 0; }
fail() { FAIL=$((FAIL+1)); echo "  ${RED}x${RST} $1"; [ -n "${2:-}" ] && echo "      ${DIM}${2}${RST}"; return 0; }
skip() { SKIP=$((SKIP+1)); echo "  ${DIM}- 跳过：$1${RST}"; [ -n "${2:-}" ] && echo "      ${DIM}${2}${RST}"; return 0; }
sect() { [ "$QUIET" = "1" ] || echo ""; [ "$QUIET" = "1" ] || echo "${DIM}-- $1 --${RST}"; return 0; }
did()  { FIXED="${FIXED}
      . $1"; echo "      ${GRN}已修复：$1${RST}"; return 0; }
note() { echo "      ${DIM}$1${RST}"; return 0; }

cd "$(dirname "$0")/.." || { echo "找不到仓库目录"; exit 2; }
REPO="$(pwd)"
ENVF="$REPO/.env"

# ---------------------------------------------------------------- 环境
sect "环境"

if [ -f /.dockerenv ]; then
    fail "这个脚本要在宿主机上跑，不是在容器里。"
    exit 2
fi
pass "运行在宿主机上"

IS_ROOT=0
[ "$(id -u)" = "0" ] && IS_ROOT=1
if [ "$IS_ROOT" = "1" ]; then
    pass "以 root 运行"
else
    warn "不是 root，防火墙检查和权限修复会跳过" "完整体检请用：sudo $0"
fi

HAVE_CURL=0
command -v curl >/dev/null 2>&1 && HAVE_CURL=1
[ "$HAVE_CURL" = "1" ] || warn "找不到 curl，所有联网检查会跳过" "apt install curl"

if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
    pass "docker 与 compose v2 可用"
else
    fail "找不到 docker 或 compose v2 插件" "安装：curl -fsSL https://get.docker.com | sh"
    exit 2
fi

# ---------------------------------------------------------------- .env
sect ".env 配置"

if [ ! -f "$ENVF" ]; then
    fail ".env 不存在" "先跑 ./install.sh"
    exit 2
fi
pass ".env 存在"

# 剥掉值两侧的引号。.env 里 DB_PASSWORD="secret" 和 DB_PASSWORD=secret 对 Laravel
# 是同一个值，但字符串比较不是——不剥的话前者会被当成「已改」而放过。
getenv() {
    local v
    v="$(grep "^$1=" "$ENVF" 2>/dev/null | head -1 | cut -d= -f2-)"
    v="${v%$'\r'}"
    case "$v" in
        \"*\") v="${v#\"}"; v="${v%\"}" ;;
        \'*\') v="${v#\'}"; v="${v%\'}" ;;
    esac
    printf '%s' "$v"
}

APP_URL="$(getenv APP_URL)"
# 后台可能被 ADMIN_PATH 搬走了。写死探 /admin 的话，搬过之后这一项必然报失败——
# 而那是配置生效的正常结果，不是故障。
ADMIN_SEG="$(getenv ADMIN_PATH)"; ADMIN_SEG="${ADMIN_SEG:-admin}"
case "$ADMIN_SEG" in *[!A-Za-z0-9_-]*|'') ADMIN_SEG="admin" ;; esac
DOMAIN="${APP_URL#http://}"; DOMAIN="${DOMAIN#https://}"; DOMAIN="${DOMAIN%/}"

case "$APP_URL" in
    https://*)
        if [ -n "$DOMAIN" ]; then pass "APP_URL = $APP_URL"; else fail "APP_URL 解析不出主机名：$APP_URL"; fi ;;
    http://*|*localhost*|'')
        fail "APP_URL 是 ${APP_URL:-（空）}，必须是 https://你的域名" \
             "它决定会话 cookie 的 Secure 标志、资源链接协议和订单链接。改法：./install.sh" ;;
    *)  fail "APP_URL 格式不对：$APP_URL" ;;
esac

[ "$(getenv APP_DEBUG)" = "false" ] && pass "APP_DEBUG=false" \
    || fail "APP_DEBUG 不是 false" "开着的话，任何触发 500 的访客都会看到一张把整个 .env 打印出来的错误页"

[ "$(getenv APP_ENV)" = "production" ] && pass "APP_ENV=production" \
    || warn "APP_ENV=$(getenv APP_ENV)，不是 production"

[ -n "$(getenv APP_KEY)" ] && pass "APP_KEY 已生成" \
    || fail "APP_KEY 为空" "容器首次启动会自动生成；仍为空说明启动流程出过错"

DBPW="$(getenv DB_PASSWORD)"
if [ -z "$DBPW" ] || [ "$DBPW" = "secret" ]; then
    fail "DB_PASSWORD 还是默认值" "数据库卷已初始化的话不能直接改 .env，见 DEPLOY.md 排查章节"
else
    pass "DB_PASSWORD 已改（非默认值）"
fi

# TRUSTED_PROXIES 是全文唯一一项「检查对象」和「生效对象」不是同一个东西的配置。
# docker-compose.yml:31 用 TRUSTED_PROXIES=${TRUSTED_PROXIES:-} 在**创建容器那一刻**把
# .env 的值快照进容器；bootstrap/app.php:49 在 .env 被解析之前就读它，而 phpdotenv 是
# immutable 的——已存在的环境变量胜过 .env 里的行。所以改了 .env 而没重建容器，跑着的
# 应用用的还是旧值，restart 和宿主机重启都不会刷新它。两个都得查。
TP="$(getenv TRUSTED_PROXIES)"
case "$TP" in
    '')  pass ".env 里 TRUSTED_PROXIES 为空（正确，真实 IP 由 nginx 还原）" ;;
    '*') fail ".env 里 TRUSTED_PROXIES=* —— 任何人伪造 X-Forwarded-For 就能绕过全部按 IP 的防护" \
              "修复：./install.sh 会自动清掉它"
         if [ "$FIX" = "1" ]; then
             sed -i 's|^TRUSTED_PROXIES=.*|TRUSTED_PROXIES=|' "$ENVF" \
                 && did ".env 里的 TRUSTED_PROXIES 已清空（容器是否生效见下一项）"
         fi ;;
    *)   warn ".env 里 TRUSTED_PROXIES=$TP（自定义网段）" "用 Cloudflare 的话这里应该留空" ;;
esac

# 前缀 x 用来把「exec 失败」和「变量确实是空」区分开：两者输出都是空字符串，而命令
# 替换套上管道之后 $? 只反映 tr 的退出码，指望不上。
TP_PROBE='printf "x%s" "${TRUSTED_PROXIES-}"'
CTP_RAW="$(docker compose exec -T app sh -c "$TP_PROBE" 2>/dev/null | tr -d '\r')"
if [ -z "$CTP_RAW" ]; then
    skip "容器里实际生效的 TRUSTED_PROXIES" "app 容器没起来或 exec 失败，读不到真正生效的值"
else
    CTP="${CTP_RAW#x}"
    if [ "$CTP" = "*" ]; then
        fail "容器里生效的 TRUSTED_PROXIES 仍是 * —— 限流、IP 黑名单、每 IP 未支付订单上限全部可绕过" \
             "改 .env 不影响已创建的容器。生效：docker compose up -d app"
        if [ "$FIX" = "1" ] && [ -z "$(getenv TRUSTED_PROXIES)" ]; then
            docker compose up -d app >/dev/null 2>&1
            NEWTP="$(docker compose exec -T app sh -c "$TP_PROBE" 2>/dev/null | tr -d '\r')"
            if [ "$NEWTP" = "x" ]; then
                did "app 容器已按新的 .env 重建，容器里的 TRUSTED_PROXIES 现已为空"
            else
                note "重建后容器里仍是 ${NEWTP#x}，请手工执行：docker compose up -d --force-recreate app"
            fi
        fi
    elif [ -n "$CTP" ]; then
        warn "容器里生效的 TRUSTED_PROXIES=$CTP（自定义网段）" "用 Cloudflare 的话应该是空的"
    elif [ -n "$TP" ]; then
        warn "容器里生效的值是空的（正确），但 .env 写着 TRUSTED_PROXIES=$TP" \
             "下一次 docker compose up -d 会把它带进容器"
    else
        pass "容器里生效的 TRUSTED_PROXIES 为空（.env 与容器一致）"
    fi
fi

# ---------------------------------------------------------------- .env 权限
sect ".env 权限"

# stat 读属组不需要 root，所以这一项对普通用户也要跑——它恰恰是最该给非 root 看的：
# 非 root 跑 install.sh 正是造成「640 + 错属组」的场景。只有修复需要 root。
MODE="$(stat -c '%a' "$ENVF" 2>/dev/null || echo '')"
GID="$(stat -c '%g' "$ENVF" 2>/dev/null || echo '')"
if [ -z "$MODE" ]; then
    skip ".env 属组与权限" "这个平台的 stat 不支持 -c，读不到"
elif [ "$MODE" = "640" ] && [ "$GID" = "33" ]; then
    pass ".env 权限 640，属组 www-data(33)"
else
    warn ".env 权限是 $MODE，属组 gid ${GID:-?}（期望 640 / 33）" \
         "640+gid33 时容器里的 worker 能读、宿主机其他用户读不到"
    if [ "$FIX" = "1" ] && [ "$IS_ROOT" = "1" ]; then
        # 每一步的退出码都要看。之前的写法把 did 挂在最后一条命令上，chgrp/chmod
        # 全失败也照样打印「已修复」——而那正是把站点锁死的那个组合。
        if chgrp 33 "$ENVF" 2>/dev/null && chmod 640 "$ENVF" 2>/dev/null; then
            NM="$(stat -c '%a' "$ENVF" 2>/dev/null)"; NG="$(stat -c '%g' "$ENVF" 2>/dev/null)"
            if [ "$NM" = "640" ] && [ "$NG" = "33" ]; then
                did ".env 已设为 640 属组 33"
            else
                note "改完复验仍是 $NM / gid $NG，请手工处理"
            fi
        else
            note "chgrp 或 chmod 失败，请手工执行：chgrp 33 $ENVF && chmod 640 $ENVF"
        fi
    elif [ "$FIX" = "1" ]; then
        note "修复需要 root：sudo $0 --fix"
    fi
fi

# ---------------------------------------------------------------- 容器
sect "容器"

RUNNING="$(docker compose ps --services --status running 2>/dev/null)"
for svc in app nginx postgres redis scheduler; do
    if printf '%s\n' "$RUNNING" | grep -qx "$svc"; then
        pass "$svc 在运行"
    else
        fail "$svc 没有运行" "docker compose up -d"
    fi
done

APP_RESTARTED=0
if printf '%s\n' "$RUNNING" | grep -qx app; then
    # .env 能不能被 PHP-FPM 的工作进程读到 —— 读不到就是全站 500，而启动日志一片绿
    if docker compose exec -T app su -s /bin/sh -c 'test -r /var/www/html/.env' www-data 2>/dev/null; then
        pass "www-data 能读到 .env"
    else
        fail "www-data 读不到 .env —— 全站会 500，而启动日志看起来一切正常" \
             "修复：sudo chgrp 33 .env && sudo chmod 640 .env && docker compose restart app"
        if [ "$FIX" = "1" ] && [ "$IS_ROOT" = "1" ]; then
            if chgrp 33 "$ENVF" 2>/dev/null && chmod 640 "$ENVF" 2>/dev/null; then
                docker compose restart app >/dev/null 2>&1
                APP_RESTARTED=1
                if docker compose exec -T app su -s /bin/sh -c 'test -r /var/www/html/.env' www-data 2>/dev/null; then
                    did ".env 权限已修，app 已重启，www-data 现在能读到"
                else
                    note "重启后 www-data 仍读不到，请手工检查 ls -l $ENVF"
                fi
            else
                note "chgrp/chmod 失败，请手工执行：sudo chgrp 33 $ENVF && sudo chmod 640 $ENVF"
            fi
        fi
    fi

    # scheduler 以 root 跑会产生 root 属主的日志文件，之后 www-data 追加失败
    SCHED_UID="$(docker compose exec -T scheduler id -u 2>/dev/null | tr -d '\r')"
    if [ "$SCHED_UID" = "33" ]; then
        pass "scheduler 以 www-data(33) 运行"
    elif [ -z "$SCHED_UID" ]; then
        skip "scheduler 运行身份" "读不到（容器没起来或 exec 失败）"
    else
        warn "scheduler 以 uid $SCHED_UID 运行（期望 33）" \
             "它跨天新建的日志文件会是 root 属主，之后 www-data 写不进去，表现为半夜开始随机 500"
        if [ "$FIX" = "1" ]; then
            docker compose up -d --force-recreate scheduler >/dev/null 2>&1
            NEWU="$(docker compose exec -T scheduler id -u 2>/dev/null | tr -d '\r')"
            [ "$NEWU" = "33" ] && did "scheduler 已重建，现在以 www-data 运行" \
                || note "重建后仍是 uid ${NEWU:-?}，检查 docker-compose.yml 里 scheduler 的 user 字段"
        fi
    fi
fi

# 上面如果重启/重建过 app，容器需要时间把 PHP-FPM 拉起来。不等的话下面的渲染检查
# 探到的是正在启动的容器，会把 5 项全部误报成失败。
if [ "$APP_RESTARTED" = "1" ]; then
    note "app 刚重启，等待它就绪……"
    i=0
    while [ "$i" -lt 30 ]; do
        docker compose exec -T app php -r 'exit(0);' >/dev/null 2>&1 && break
        i=$((i+1)); sleep 2
    done
fi

# ---------------------------------------------------------------- 站点渲染
sect "站点渲染"

# 只看状态码会被骗：Laravel 的 500 错误页也是一整张 HTML。csrf-token 只出现在应用的
# 布局模板里，框架的错误页没有它 —— 抓到它才说明页面真的渲染出来了。
probe() { # probe <url> <说明>
    local body n
    if [ "$HAVE_CURL" != "1" ]; then skip "$2" "没有 curl"; return 0; fi
    body="$(curl -sk --max-time 20 "$1" 2>/dev/null)"
    n="$(printf '%s' "$body" | grep -c 'csrf-token' 2>/dev/null | head -1)"
    case "$n" in ''|*[!0-9]*) n=0 ;; esac
    if [ "$n" -gt 0 ]; then
        pass "$2"
    elif printf '%s' "$body" | grep -q 'Server Error\|Whoops'; then
        fail "$2 —— 返回的是错误页" "docker compose logs --tail=50 app"
    elif [ -z "$body" ]; then
        fail "$2 —— 没有响应"
    else
        fail "$2 —— 页面里没有 csrf-token，可能不是应用的页面"
    fi
    return 0
}

probe "http://127.0.0.1/" "源站本地 HTTP 能渲染页面"
[ -f "$REPO/docker/nginx/tls/ssl.conf" ] && probe "https://127.0.0.1/" "源站本地 HTTPS 能渲染页面"

if [ -n "$DOMAIN" ]; then
    probe "https://$DOMAIN/"             "经 Cloudflare 访问首页"
    probe "https://$DOMAIN/order/query"  "经 Cloudflare 访问订单查询"
    probe "https://$DOMAIN/$ADMIN_SEG"   "经 Cloudflare 访问后台（/$ADMIN_SEG）"
else
    skip "经 Cloudflare 的页面检查" "APP_URL 里没有可用的域名"
fi

# ---------------------------------------------------------------- HTTPS / Cloudflare
sect "HTTPS 与 Cloudflare"

if [ "$HAVE_CURL" != "1" ]; then
    skip "Cloudflare / HSTS / 跳转检查" "没有 curl"
elif [ -z "$DOMAIN" ]; then
    skip "Cloudflare / HSTS / 跳转检查" "APP_URL 里没有可用的域名"
else
    HDR="$(curl -sI --max-time 20 "https://$DOMAIN/" 2>/dev/null)"
    printf '%s' "$HDR" | grep -qi '^cf-ray:' \
        && pass "流量确实经过 Cloudflare（有 CF-RAY）" \
        || fail "响应里没有 CF-RAY，流量没走 Cloudflare" "检查 DNS 记录的代理状态是不是橙色云朵"

    printf '%s' "$HDR" | grep -qi '^strict-transport-security:' \
        && pass "HSTS 已启用" \
        || warn "HSTS 未启用" "Cloudflare → SSL/TLS → Edge Certificates → 启用 HSTS"

    RC="$(curl -sI --max-time 20 "http://$DOMAIN/" 2>/dev/null | head -1 | awk '{print $2}')"
    case "$RC" in
        301|302|308) pass "HTTP 自动跳转 HTTPS（$RC）" ;;
        *) warn "HTTP 没有跳转 HTTPS（返回 ${RC:-无响应}）" "Cloudflare → SSL/TLS → Edge Certificates → 始终使用 HTTPS" ;;
    esac
fi

TLS_DIR="$(getenv TLS_CERT_DIR)"; TLS_DIR="${TLS_DIR:-/opt/cf}"
SSLC="$REPO/docker/nginx/tls/ssl.conf"
if [ -f "$SSLC" ]; then
    if [ -s "$TLS_DIR/cert.pem" ] && [ -s "$TLS_DIR/key.pem" ]; then
        pass "源站证书就位，443 已启用（$TLS_DIR）"
        KM="$(stat -c '%a' "$TLS_DIR/key.pem" 2>/dev/null)"
        [ "$KM" = "600" ] && pass "私钥权限 600" || warn "私钥权限是 ${KM:-?}，建议 600" "chmod 600 $TLS_DIR/key.pem"
    else
        fail "ssl.conf 存在但证书文件缺失或为空，nginx 会完全起不来（连 80 一起）" \
             "检查 $TLS_DIR/cert.pem 和 key.pem，或删掉 $SSLC 先恢复 80"
    fi
elif [ -s "$TLS_DIR/cert.pem" ] && [ -s "$TLS_DIR/key.pem" ]; then
    warn "证书已就位但没启用 443（缺 ssl.conf），CF 到源站这一段是明文" \
         "修复：./install.sh --recommended && docker compose up -d --force-recreate nginx"
    if [ "$FIX" = "1" ]; then
        # ssl.conf 是 include 进 80 端口那个 server 块的（docker/nginx/default.conf:23），
        # 所以 TLS 配置一旦有问题，挂的不是 443，是整个 nginx —— 原本正常的 80 一起没。
        # 而 nginx 没有 healthcheck，up -d 在容器 start 后就返回 0，崩溃发生在之后。
        # 所以：先在一次性容器里 nginx -t 验一遍，过了才动正在服务的 nginx；任何一步
        # 不对就删掉 ssl.conf 回滚，绝不留下一个能把站点打死的中间态。
        if ! cp "$REPO/docker/nginx/tls/ssl.conf.example" "$SSLC" 2>/dev/null; then
            fail "无法写入 $SSLC，443 未启用" "检查目录权限"
        elif ! docker compose run --rm --no-deps -T nginx nginx -t >/dev/null 2>&1; then
            rm -f "$SSLC"
            fail "证书无法通过 nginx 配置校验，已回滚（ssl.conf 已删除），站点不受影响" \
                 "多半是证书或私钥内容不完整。校验详情：docker compose run --rm --no-deps nginx nginx -t"
        else
            docker compose up -d --force-recreate nginx >/dev/null 2>&1
            sleep 3
            if printf '%s\n' "$(docker compose ps --services --status running 2>/dev/null)" | grep -qx nginx; then
                did "已启用 ssl.conf 并重建 nginx（配置已通过 nginx -t 校验）"
            else
                rm -f "$SSLC"
                docker compose up -d --force-recreate nginx >/dev/null 2>&1
                fail "重建后 nginx 没起来，已删除 ssl.conf 并回滚" "docker compose logs --tail=30 nginx"
            fi
        fi
    fi
else
    warn "还没配源站证书，CF 到源站这一段是明文" "见 DEPLOY.md 第 5 步"
fi

# ---------------------------------------------------------------- 容器出站
sect "容器出站"

if ! printf '%s\n' "$RUNNING" | grep -qx app; then
    skip "容器出站检查" "app 容器没在运行"
else
    OKN=0
    for h in https://api.github.com https://www.cloudflare.com; do
        C="$(docker compose exec -T app curl -m 12 -so /dev/null -w '%{http_code}' "$h" 2>/dev/null || echo 000)"
        case "$C" in 2*|3*|4*) OKN=$((OKN+1)) ;; esac
    done
    if [ "$OKN" -gt 0 ]; then
        pass "容器能访问外网（$OKN/2 个探针可达）"
    else
        fail "容器出站不通 —— 支付跳转、Turnstile、Telegram 通知、邮件都会失效" \
             "多半是防火墙规则漏了 -i 网卡限定。回滚：sudo ./scripts/cf-only-firewall.sh --remove"
    fi
fi

# ---------------------------------------------------------------- 防火墙
sect "源站防护"

check_fw() { # check_fw <iptables|ip6tables> <标签>
    local ipt="$1" tag="$2" rules jat rat jrule
    if ! $ipt -n -L CF_ONLY >/dev/null 2>&1; then
        fail "$tag：CF_ONLY 链不存在，源站对全网开放" \
             "任何人拿到你的 IP 就能绕过 Cloudflare。修复：sudo ./scripts/cf-only-firewall.sh --apply --persist"
        return 0
    fi
    pass "$tag：CF_ONLY 链存在"

    $ipt -S CF_ONLY 2>/dev/null | sed -n '2p' | grep -q 'ESTABLISHED' \
        && pass "$tag：第一条是 ESTABLISHED 放行" \
        || fail "$tag：第一条不是 ESTABLISHED 放行，已建立的连接会被打断"

    $ipt -S CF_ONLY 2>/dev/null | tail -1 | grep -q -- '-j DROP' \
        && pass "$tag：最后一条是 DROP" \
        || fail "$tag：最后一条不是 DROP，等于没有防护"

    rules="$($ipt -S DOCKER-USER 2>/dev/null)"
    jrule="$(printf '%s\n' "$rules" | grep -- '-j CF_ONLY' | head -1)"
    if [ -z "$jrule" ]; then
        fail "$tag：DOCKER-USER 里没有跳转到 CF_ONLY 的规则，防护未生效" \
             "sudo ./scripts/cf-only-firewall.sh --apply --persist"
        return 0
    fi

    # 只匹配 -j CF_ONLY 是不够的：一条没限定入口网卡的跳转会把容器出站也送进 CF_ONLY，
    # 而容器的源 IP 不在 CF 网段内，整条出站链路会被 DROP —— 这个故障真实发生过。
    printf '%s' "$jrule" | grep -q -- '-i ' \
        && pass "$tag：跳转限定了入口网卡（容器出站不会被误伤）" \
        || fail "$tag：跳转没有限定 -i 入口网卡，容器出站会被一起 DROP" \
                "重装：sudo ./scripts/cf-only-firewall.sh --remove && sudo ./scripts/cf-only-firewall.sh --apply --persist"

    printf '%s' "$jrule" | grep -q -- '--dports' \
        && pass "$tag：跳转限定了端口" \
        || warn "$tag：跳转没有限定端口，会把该网卡上所有转发流量送进 CF_ONLY"

    jat="$(printf '%s\n' "$rules" | grep -n -- '-j CF_ONLY' | head -1 | cut -d: -f1 || true)"
    rat="$(printf '%s\n' "$rules" | grep -n -E '^-A DOCKER-USER -j RETURN$' | head -1 | cut -d: -f1 || true)"
    case "$jat" in ''|*[!0-9]*) jat="" ;; esac
    case "$rat" in ''|*[!0-9]*) rat="" ;; esac
    if [ -n "$jat" ] && [ -n "$rat" ] && [ "$jat" -gt "$rat" ]; then
        fail "$tag：跳转排在无条件 RETURN 之后，永远执行不到，防护为零" \
             "sudo ./scripts/cf-only-firewall.sh --remove && sudo ./scripts/cf-only-firewall.sh --apply --persist"
    else
        pass "$tag：跳转规则可达（排在 RETURN 之前）"
    fi
    return 0
}

if [ "$IS_ROOT" != "1" ]; then
    skip "防火墙规则检查" "需要 root 才能读 iptables，请用 sudo 重跑"
elif ! command -v iptables >/dev/null 2>&1; then
    skip "防火墙规则检查" "这台机器上找不到 iptables"
else
    check_fw iptables "IPv4"

    # 只做 IPv4 会留下 v6 旁路：宿主机有公网 v6 且 Docker 管着 ip6tables 时，
    # 别人可以用 v6 直连源站，把整套 CF-only 绕过去，而 IPv4 那边全绿。
    if ip -6 addr show scope global 2>/dev/null | grep -q inet6; then
        if command -v ip6tables >/dev/null 2>&1 && ip6tables -n -L DOCKER-USER >/dev/null 2>&1; then
            check_fw ip6tables "IPv6"
        else
            warn "宿主机有公网 IPv6，但 Docker 没接管 ip6tables" \
                 "存在 IPv6 旁路：别人可以用 v6 直连源站绕过防护。在 /etc/docker/daemon.json 设 ip6tables:true，或在云控制台限制 v6 入站"
        fi
    else
        pass "宿主机没有公网 IPv6（无 v6 旁路）"
    fi

    if systemctl list-unit-files 2>/dev/null | grep -q '^cf-only-firewall.service'; then
        systemctl is-enabled --quiet cf-only-firewall 2>/dev/null \
            && pass "开机自启已启用" \
            || warn "cf-only-firewall 单元存在但没启用" "重启后规则会消失：sudo systemctl enable --now cf-only-firewall"
        systemctl is-active --quiet cf-only-firewall 2>/dev/null \
            && pass "开机自启单元状态正常（active）" \
            || warn "单元当前不是 active" "本次开机没执行过。验一次：sudo systemctl start cf-only-firewall"
    else
        warn "没有安装开机自启单元" "重启后防火墙规则会消失，而站点访问一切正常，你不会察觉"
    fi
fi

# 防火墙不做自动应用：cf-only-firewall.sh 自己有一道「探测到的网卡请人工确认」的闸，
# --fix 传 --yes 会把它跳过。网卡探错的后果是防护为零或容器全网断，都不该由一个
# 体检脚本替人决定。

# 源站直连必须从**外部**测：本机访问自己的公网 IP 不经过 FORWARD 链，必然是「能连上」
sect "源站直连（需要从外部验证）"
if [ "$HAVE_CURL" = "1" ]; then
    PUBIP="$(curl -s --max-time 10 https://api.ipify.org 2>/dev/null)"
else
    PUBIP=""
fi
skip "源站直连是否被封死" "本机测不了：连自己的公网 IP 不经过 DOCKER-USER 所在的 FORWARD 链，结果必然是「能连上」"
if [ -n "$PUBIP" ]; then
    note "请在你自己电脑上跑：curl -m 10 http://$PUBIP/ ; echo \"退出码 \$?\""
    note "应该超时（退出码 28）。返回了页面就说明源站还能被绕过 Cloudflare 直连。"
fi

# ---------------------------------------------------------------- 汇总
echo ""
echo "${DIM}======================================${RST}"
echo "  通过 ${GRN}${PASS}${RST}   警告 ${YEL}${WARN}${RST}   失败 ${RED}${FAIL}${RST}   跳过 ${DIM}${SKIP}${RST}"
[ -n "$FIXED" ] && echo "
  本次修复：${FIXED}"
[ "$SKIP" -gt 0 ] && echo "
  ${DIM}有 $SKIP 项没有检查（见上面的「跳过」）——「没检查」不等于「没问题」。${RST}"
if [ "$FAIL" -gt 0 ]; then
    echo ""
    if [ "$FIX" = "1" ]; then
        echo "  有失败项，部分需要手工处理，见上面的提示。"
    else
        echo "  有失败项。能自动修的可以试：sudo $0 --fix"
    fi
    exit 2
elif [ "$WARN" -gt 0 ] || [ "$SKIP" -gt 0 ]; then
    echo ""
    echo "  没有失败项，但有警告或未检查项值得看一眼。"
    exit 1
else
    echo ""
    echo "  ${GRN}全部通过。${RST}"
    exit 0
fi
