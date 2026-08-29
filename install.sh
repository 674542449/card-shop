#!/usr/bin/env bash
#
# 根据这台服务器的实际配置计算并写入性能参数。
#
# 为什么需要这个：镜像里 PHP-FPM 用的是官方默认值 —— 只有 5 个工作进程。
# 意思是同时 5 个请求就占满了，第 6 个开始排队，一个人开十几个慢连接就能让
# 整站无响应，而这时候机器可能只用了百分之一的内存。默认值是按"任何机器都
# 能跑起来"选的，不是按"这台机器"选的。
#
# 这个脚本读取真实的核数和内存，给出几档推荐让你选，写进 .env。
# 容器启动时会应用这些值，不需要重新构建镜像。
#
# 用法：
#   ./install.sh              交互选择
#   ./install.sh --recommended 直接采用推荐档，不提问（适合脚本/CI）
#   ./install.sh --show       只显示检测结果和各档数值，不写任何文件

set -euo pipefail

cd "$(dirname "$0")"

ENV_FILE=".env"
MODE="interactive"

for arg in "$@"; do
    case "$arg" in
        --recommended) MODE="auto" ;;
        --show) MODE="show" ;;
        -h|--help) sed -n '2,20p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "未知参数：$arg（可用：--recommended / --show / --help）"; exit 1 ;;
    esac
done

# ---------------------------------------------------------------- 依赖提醒
# 这个脚本本身只写 .env，不需要 docker。但缺了 docker 的话，用户会一路顺利跑完
# 这里、然后在 `docker compose up -d` 那一步撞上一条看不懂的报错，并且多半会以为
# 是这个脚本坏了。所以提前说清楚。
MISSING=""
command -v docker >/dev/null 2>&1 || MISSING="docker"
if [ -z "$MISSING" ] && ! docker compose version >/dev/null 2>&1; then
    MISSING="docker compose 插件（v2）"
fi
if [ -n "$MISSING" ]; then
    echo
    echo "  提醒：没检测到 $MISSING。.env 照样会写，但还起不了服务。"
    echo "  安装：curl -fsSL https://get.docker.com | sh"
    echo
fi

# ---------------------------------------------------------------- 检测硬件

CORES="$(nproc 2>/dev/null || echo 1)"
MEM_MB="$(awk '/^MemTotal:/ { print int($2 / 1024) }' /proc/meminfo 2>/dev/null || echo 1024)"
DISK_AVAIL_GB="$(df -Pm . 2>/dev/null | awk 'NR==2 { print int($4 / 1024) }' || echo 0)"

# ---------------------------------------------------------------- 计算推荐值
#
# PHP 每个工作进程按 64MB 估算（Laravel 请求实测 30-60MB，取上限留余量）。
# 先扣掉系统、数据库、缓存、Docker 自身要用的部分，剩下的一半给 PHP —— 另一半
# 留给页面缓存，它对数据库读取的影响比多开几个进程更大。

reserve_mb() {
    # 小机器按固定值预留，大机器按比例，两者取大。
    local pct=$(( MEM_MB / 5 ))
    if [ "$pct" -gt 1024 ]; then echo "$pct"; else echo 1024; fi
}

clamp() { # clamp <值> <下限> <上限>
    local v=$1 lo=$2 hi=$3
    [ "$v" -lt "$lo" ] && v=$lo
    [ "$v" -gt "$hi" ] && v=$hi
    echo "$v"
}

RESERVE=$(reserve_mb)
PHP_BUDGET=$(( (MEM_MB - RESERVE) / 2 ))
[ "$PHP_BUDGET" -lt 128 ] && PHP_BUDGET=128

# 两个约束取小值。
#
# 内存侧：预算能放下几个进程。
# CPU 侧：每核 5 个。PHP 请求大部分时间在等数据库、等支付网关回应，所以可以
#   超过核数；但超太多只会让进程互相抢 CPU，排队反而比抖动好。
# 最后再封顶 64 —— 一个发卡站同时处理 64 个 PHP 请求已经远超实际需要，真到那个
#   量级该换架构而不是继续加进程。多出来的内存留给页面缓存更有价值。
BY_MEM=$(( PHP_BUDGET / 64 ))
BY_CPU=$(( CORES * 5 ))
RECOMMENDED=$(( BY_MEM < BY_CPU ? BY_MEM : BY_CPU ))
RECOMMENDED=$(clamp "$RECOMMENDED" 5 64)

CONSERVATIVE=$(clamp $(( RECOMMENDED / 2 )) 5 200)
AGGRESSIVE=$(clamp $(( RECOMMENDED * 2 )) 5 300)

# PostgreSQL。这个站的数据量很小（商品、订单、卡密），给再多也用不上，
# 所以按比例算之后要封顶 —— 内存留给页面缓存更有价值。
pg_shared() {
    local v=$(( MEM_MB / 4 ))
    v=$(clamp "$v" 128 1024)
    echo "${v}MB"
}
pg_cache() {
    local v=$(( MEM_MB / 2 ))
    v=$(clamp "$v" 256 6144)
    echo "${v}MB"
}

PG_SHARED="$(pg_shared)"
PG_CACHE="$(pg_cache)"
PG_WORK_MEM=$([ "$MEM_MB" -ge 4096 ] && echo "16MB" || echo "4MB")

# ---------------------------------------------------------------- 显示

fmt_mem() { # 把 max_children 换算成内存上限，让选择有实感
    echo "约 $(( $1 * 64 / 1024 )).$(( ($1 * 64 % 1024) * 10 / 1024 )) GB 上限"
}

echo
echo "=============================================================="
echo "  检测到的服务器配置"
echo "=============================================================="
printf "  CPU 核心      : %s\n" "$CORES"
printf "  内存          : %s MB\n" "$MEM_MB"
printf "  当前目录可用磁盘: %s GB\n" "$DISK_AVAIL_GB"
echo
if [ "$MEM_MB" -lt 900 ]; then
    echo "  ⚠ 内存低于 1GB。首次部署时 composer 解析依赖可能被 OOM 杀掉，"
    echo "    建议先加 2GB swap 再继续。"
    echo
fi
if [ "$DISK_AVAIL_GB" -lt 15 ] && [ "$DISK_AVAIL_GB" -gt 0 ]; then
    echo "  ⚠ 可用磁盘不足 15GB。光是镜像就要约 2GB，再加数据和日志会很紧张。"
    echo
fi
echo "=============================================================="
echo "  PHP-FPM 工作进程数（决定能同时处理多少个请求）"
echo "=============================================================="
printf "  1) 保守    %3s 个   %s\n" "$CONSERVATIVE" "$(fmt_mem $CONSERVATIVE)"
printf "  2) 推荐    %3s 个   %s   ← 按你这台机器算出来的\n" "$RECOMMENDED" "$(fmt_mem $RECOMMENDED)"
printf "  3) 高并发  %3s 个   %s\n" "$AGGRESSIVE" "$(fmt_mem $AGGRESSIVE)"
echo "  4) 自定义"
echo
echo "  当前镜像默认值是 5 个 —— 这是所有配置都能跑起来的保底值，不是适合你的值。"
echo

# ---------------------------------------------------------------- 生产就绪体检
# 这几项每一条都能独立毁掉一个站点，而且全是静默的：不会报错，只是不安全。
#
# 做成函数是为了 --show 也能调用。一个叫「只显示、不改任何文件」的模式，本来就
# 应该把只读的检查跑完 —— 否则用户看到的只有 CPU 和内存，看不到 .env 里真正该改的东西。
# 传 dry=1 时唯一的区别：不复制 ssl.conf，只说明该复制。
readiness_check() {
    local dry="${1:-0}"
    get_env() { grep "^$1=" "$ENV_FILE" 2>/dev/null | cut -d= -f2- || true; }
    ISSUES=0
    note() { ISSUES=$((ISSUES+1)); printf "  [%d] %s
    " "$ISSUES" "$1"; }

    echo "=============================================================="
    echo "  上线前体检"
    echo "=============================================================="

    case "$(get_env APP_URL)" in
        https://*) ;;
        *localhost*|'') note "APP_URL 还是 $(get_env APP_URL)。它决定会话 cookie 的 Secure 标志（config/session.php）、
          Laravel 生成的资源链接协议、以及 canonical/og:url。不改成 https://你的域名，
          会话 cookie 就没有 Secure，HTTPS 页面还会因混合内容加载不到 CSS。" ;;
        http://*) note "APP_URL 是 http://。挂了 HTTPS 就要改成 https://，理由同上。" ;;
    esac

    [ "$(get_env APP_DEBUG)" = "false" ] || note "APP_DEBUG 不是 false。开着的话，任何触发 500 的访客都会看到一张
          把整个 .env 打印出来的错误页 —— 数据库密码、商户密钥、EPUSDT Token、SMTP 密码全在里面。"

    [ "$(get_env APP_ENV)" = "production" ] || note "APP_ENV 不是 production（当前：$(get_env APP_ENV)）。"

    [ -n "$(get_env APP_KEY)" ] || note "APP_KEY 为空。容器首次启动会自动生成，通常不用管；若启动后仍为空则需排查。"

    [ "$(get_env DB_PASSWORD)" != "secret" ] || note "DB_PASSWORD 还是默认的 secret，见上面的说明。"

    # TLS：证书和 ssl.conf 缺一个，nginx 就不会监听 443。
    TLS_DIR="$(get_env TLS_CERT_DIR)"; TLS_DIR="${TLS_DIR:-/opt/cf}"
    if [ ! -f "docker/nginx/tls/ssl.conf" ]; then
        if [ -f "$TLS_DIR/cert.pem" ] && [ -f "$TLS_DIR/key.pem" ]; then
            if [ "$dry" = "1" ]; then
                echo "  证书已就位（$TLS_DIR），但 docker/nginx/tls/ssl.conf 还没建 ——"
                echo "  nginx 因此不会监听 443。跑 ./install.sh --recommended 会自动建好。"
            else
                cp -n docker/nginx/tls/ssl.conf.example docker/nginx/tls/ssl.conf 2>/dev/null || true
                echo "  已启用 HTTPS 配置：docker/nginx/tls/ssl.conf（证书在 $TLS_DIR）"
                echo "  执行 docker compose restart nginx 生效。"
            fi
        else
            note "还没配 HTTPS。nginx 现在只监听 80，Cloudflare 的 SSL 模式只能停在 Flexible，
          CF 到源站这一段是明文 —— 而这一段跑的是管理员会话 cookie 和发出去的卡密。
          三步：① Cloudflare 面板 SSL/TLS → Origin Server → Create Certificate
                ② 证书存到 $TLS_DIR/cert.pem 和 $TLS_DIR/key.pem（key 记得 chmod 600）
                ③ 重跑本脚本，它会自动启用 docker/nginx/tls/ssl.conf"
        fi
    fi

    if [ "$ISSUES" = "0" ]; then
        echo "  未发现问题。"
    fi

    echo
    echo "=============================================================="
    echo "  脚本做不到、需要你人工完成的"
    echo "=============================================================="
    echo "  1) Cloudflare 面板：A 记录指向本机并开启橙云代理（不开的话，nginx 里那 22 条"
    echo "     set_real_ip_from 永远匹配不上，真实 IP 还原会静默失效，源站 IP 也直接暴露）；"
    echo "     SSL/TLS 设为 Full (strict)；开启 Always Use HTTPS 和 HSTS。"
    echo "  2) 云控制台安全列表（Oracle / AWS）：这是独立于本机防火墙的一层，也要放行 80/443。"
    echo "  3) 源站直连防护（强烈建议）：Docker 发布的端口 ufw 拦不住，需要单独的脚本："
    echo "       sudo ./scripts/cf-only-firewall.sh --check --domain=你的域名   # 先体检"
    echo "       sudo ./scripts/cf-only-firewall.sh --apply --domain=你的域名 --persist"
    echo "     它只放行 Cloudflare 网段访问 80/443。有 --check 先验、应用后自动验出站、"
    echo "     不通会自动回滚。本脚本不会替你执行它 —— 改宿主机防火墙的风险等级和写 .env 差太远。"
    echo "  4) 后台开启 Turnstile 人机验证（默认是关的，不配等于下单和订单查询没有任何验证）。"
    echo
}


if [ "$MODE" = "show" ]; then
    echo "  PostgreSQL 将使用：shared_buffers=$PG_SHARED  effective_cache_size=$PG_CACHE  work_mem=$PG_WORK_MEM"
    echo
    # 体检是只读的，--show 也要跑：这个模式的意义就是「让我先看看现在什么情况」。
    if [ -f "$ENV_FILE" ]; then
        readiness_check 1
    else
        echo "  （还没有 .env，跳过上线前体检）"
    fi
    echo
    echo "（--show 模式，未写入任何文件）"
    exit 0
fi

CHOICE="$RECOMMENDED"

if [ "$MODE" = "interactive" ]; then
    printf "请选择 [1-4，直接回车用推荐值]: "
    read -r answer || answer=""
    case "${answer:-2}" in
        1) CHOICE="$CONSERVATIVE" ;;
        2|"") CHOICE="$RECOMMENDED" ;;
        3) CHOICE="$AGGRESSIVE" ;;
        4)
            printf "请输入工作进程数 (5-300): "
            read -r custom || custom=""
            case "$custom" in
                ''|*[!0-9]*) echo "不是有效数字，改用推荐值 $RECOMMENDED"; CHOICE="$RECOMMENDED" ;;
                *) CHOICE=$(clamp "$custom" 5 300) ;;
            esac
            ;;
        *) echo "无效选择，改用推荐值 $RECOMMENDED"; CHOICE="$RECOMMENDED" ;;
    esac
fi

# 从工作进程数推导其余参数
START_SERVERS=$(clamp $(( CHOICE / 4 )) 2 32)
MIN_SPARE=$(clamp $(( CHOICE / 6 )) 1 24)
MAX_SPARE=$(clamp $(( CHOICE / 2 )) 3 64)
# 每个 PHP 进程会占一个数据库连接，所以上限必须高于进程数，否则高峰期会报
# "too many connections"，而那是最难排查的一类故障。
PG_MAX_CONN=$(clamp $(( CHOICE + 30 )) 100 500)

# ---------------------------------------------------------------- 写入 .env

if [ ! -f "$ENV_FILE" ]; then
    if [ -f .env.example ]; then
        cp .env.example "$ENV_FILE"
        echo
        echo "已从 .env.example 创建 .env"
    else
        touch "$ENV_FILE"
    fi
fi
# .env 里有数据库密码、APP_KEY、易支付商户密钥、SMTP 密码。默认 umask 022 下它是
# 0644，宿主机上任何普通用户、以及任何被攻破的其他服务都能读到。
# 不用 `|| true` 一笔带过：chmod 失败而我们假装成功，就是「看起来安全、实际没有」，
# 而这恰恰是最不该发生在密钥文件上的。失败就明说。
if ! chmod 600 "$ENV_FILE" 2>/dev/null; then
    echo "  警告：无法把 .env 权限收紧到 600，里面有数据库密码和各种密钥，请手工处理。"
else
    ENV_MODE=$(stat -c '%a' "$ENV_FILE" 2>/dev/null || echo '')
    case "$ENV_MODE" in
        600|'') ;;   # 空表示这个平台不支持 stat -c（如 macOS/Git Bash），不误报
        *) echo "  警告：.env 权限是 $ENV_MODE 而不是 600，里面有数据库密码和各种密钥。" ;;
    esac
fi

set_env() { # set_env KEY VALUE —— 存在则替换，不存在则追加，其余行不动
    local key="$1" value="$2"
    if grep -q "^${key}=" "$ENV_FILE"; then
        # 用 | 作分隔符，值里有 / 也不会出错
        sed -i "s|^${key}=.*|${key}=${value}|" "$ENV_FILE"
    else
        printf '%s=%s\n' "$key" "$value" >> "$ENV_FILE"
    fi
}

if ! grep -q '^# ---- 性能参数' "$ENV_FILE"; then
    printf '\n# ---- 性能参数（由 install.sh 生成，可手动修改后重启生效）----\n' >> "$ENV_FILE"
fi

# ---------------------------------------------------------------- 反向代理 / CDN
#
# 这里过去会问"要不要把 TRUSTED_PROXIES 设成 *"。现在不问了，而且必须留空。
#
# 原因：docker/nginx/default.conf 里已经配了 Cloudflare 的全部回源网段
# （set_real_ip_from）加上 real_ip_header CF-Connecting-IP。nginx 只在**对端确实是
# 这些网段之一**时才改写 $remote_addr，所以 PHP 从 REMOTE_ADDR 拿到的已经是真实
# 访客 IP，Laravel 不需要再信任任何代理。
#
# 为什么不能设成 *：TRUSTED_PROXIES=* 的意思是"谁连过来都信它自称的 IP"。只要有人
# 直连源站 IP 并伪造 X-Forwarded-For，就能任意变换身份，绕过限流、绕过每 IP 未支付
# 订单上限、绕过 IP 黑名单。nginx 那套方案没有这个弱点：伪造者没办法让自己的数据包
# 从 Cloudflare 的网段发出来。
#
# 所以这里分三种情况处理：空 → 保持空；'*' → 告警并清掉；具体网段 → 保留（见下方 case）。
# `|| true` 不能省：脚本开头是 set -euo pipefail，.env 里没有 TRUSTED_PROXIES 这一行时
# grep 退出 1，pipefail 把它传给整条管道，set -e 当场终止脚本——后面的性能参数一个都不会
# 写，而且一声不吭只留个退出码 1。新装走 .env.example 不会触发（里面有这个键），但任何
# 手写过 .env 的老部署一升级就中招。
CURRENT_TP=$(grep '^TRUSTED_PROXIES=' "$ENV_FILE" 2>/dev/null | cut -d= -f2- || true)
# 只有 '*' 是必须消灭的。用户可能把站点放在自建 HAProxy / Nginx Proxy Manager /
# 非 Cloudflare 的 CDN 后面（此时 default.conf 那 22 条 CF 网段完全匹配不上，
# nginx 不会改写 $remote_addr），他手工填的回源网段是合法且必要的。而这个脚本
# 被明确鼓励反复重跑调档 —— 无条件清空会在某次调 PHP 进程数时把它悄悄抹掉，
# 所有访客 IP 塌缩成代理 IP，限流和黑名单集体失效，且没有任何报错。
TP_NOTE="(空，正确) —— 真实 IP 由 nginx 的 set_real_ip_from 还原"
case "$CURRENT_TP" in
    '*')
        echo
        echo "  注意：TRUSTED_PROXIES 是 '*'，这是旧版本的配置方式，必须清掉。"
        echo "  它的意思是「谁连过来都信它自称的 IP」—— 任何人直连源站伪造"
        echo "  X-Forwarded-For 就能绕过限流、每 IP 未支付订单上限和 IP 黑名单。"
        echo "  真实 IP 现在由 nginx 还原（docker/nginx/default.conf），留空即可。"
        echo "  已自动清空。"
        set_env TRUSTED_PROXIES ''
        ;;
    '')
        set_env TRUSTED_PROXIES ''
        ;;
    *)
        echo
        echo "  检测到自定义可信代理网段：$CURRENT_TP"
        echo "  已保留（假定 nginx 之外还套了别的反向代理）。若你用的是 Cloudflare，"
        echo "  这个值应该留空 —— 真实 IP 已由 nginx 还原。"
        TP_NOTE="$CURRENT_TP（自定义，已保留）"
        ;;
esac

# ---------------------------------------------------------------- 数据库密码
# .env.example 的默认值是 secret，docker-compose.yml 的兜底也是 secret。忘了改就是
# 用弱口令跑生产库。postgres 绑在 127.0.0.1 限制了外部直连，但宿主机上任何本地进程、
# 以及任何一次 SSRF 或命令执行，都能用默认口令直接拿到全部订单和卡密。
CURRENT_DB_PW=$(grep '^DB_PASSWORD=' "$ENV_FILE" 2>/dev/null | cut -d= -f2- || true)
DB_PW_NOTE="已设置（未改动）"
if [ -z "$CURRENT_DB_PW" ] || [ "$CURRENT_DB_PW" = "secret" ]; then
    if docker volume ls 2>/dev/null | grep -qi pgdata; then
        # 卷一旦初始化过，POSTGRES_PASSWORD 就不再生效：改 .env 只会让应用连不上库。
        DB_PW_NOTE="仍是 secret（数据库卷已存在，未自动改）"
        echo
        echo "  警告：DB_PASSWORD 还是默认的 secret，但数据库卷已经初始化过了。"
        echo "  此时改 .env 不会改库里的密码，只会让应用连不上。请手工同步："
        echo "    docker compose exec postgres psql -U cardshop -c \"ALTER USER cardshop WITH PASSWORD '新密码';\""
        echo "    再把同一个新密码写进 .env 的 DB_PASSWORD，然后 docker compose up -d"
    else
        NEW_DB_PW=$(openssl rand -base64 24 2>/dev/null | tr -d '/+=' | cut -c1-24 || true)
        if [ -n "$NEW_DB_PW" ]; then
            set_env DB_PASSWORD "$NEW_DB_PW"
            DB_PW_NOTE="已生成随机强密码（首次部署，数据库将用它初始化）"
        else
            DB_PW_NOTE="仍是 secret（本机没有 openssl，请手工改）"
        fi
    fi
fi

set_env PHP_FPM_MAX_CHILDREN   "$CHOICE"
set_env PHP_FPM_START_SERVERS  "$START_SERVERS"
set_env PHP_FPM_MIN_SPARE      "$MIN_SPARE"
set_env PHP_FPM_MAX_SPARE      "$MAX_SPARE"
set_env POSTGRES_SHARED_BUFFERS "$PG_SHARED"
set_env POSTGRES_EFFECTIVE_CACHE "$PG_CACHE"
set_env POSTGRES_WORK_MEM       "$PG_WORK_MEM"
set_env POSTGRES_MAX_CONNECTIONS "$PG_MAX_CONN"

echo
echo "=============================================================="
echo "  已写入 .env"
echo "=============================================================="
printf "  PHP-FPM 工作进程   : %s（原默认 5）\n" "$CHOICE"
printf "  启动/空闲进程      : start=%s min=%s max=%s\n" "$START_SERVERS" "$MIN_SPARE" "$MAX_SPARE"
printf "  PostgreSQL 共享缓冲: %s\n" "$PG_SHARED"
printf "  PostgreSQL 缓存估计: %s\n" "$PG_CACHE"
printf "  PostgreSQL 最大连接: %s\n" "$PG_MAX_CONN"
printf "  信任的代理        : %s
" "$TP_NOTE"
printf "  数据库密码        : %s
" "$DB_PW_NOTE"
echo
echo "  生效："
echo "    docker compose up -d"
echo
echo "  想换一档随时重跑这个脚本；也可以直接改 .env 里的数值。"
echo

readiness_check 0
