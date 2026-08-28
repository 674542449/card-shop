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

if [ "$MODE" = "show" ]; then
    echo "  PostgreSQL 将使用：shared_buffers=$PG_SHARED  effective_cache_size=$PG_CACHE  work_mem=$PG_WORK_MEM"
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
# 这一步过去不问，.env 里的 TRUSTED_PROXIES 就一直是空的。空值的后果不是"IP 显示
# 得不准确"这么轻——它会让所有按 IP 生效的保护一起失效，而且是静默失效：
#   · 每 IP 最多 3 个未支付订单的限制，变成全站合计 3 个，第 4 个买家直接被拒
#   · 下单、订单查询、后台登录的限流，全站共用一个桶
#   · IP 黑名单封一个人等于封所有人
# 所以现在必须问。
if [ "$MODE" = "interactive" ]; then
    CURRENT_TP=$(grep '^TRUSTED_PROXIES=' "$ENV_FILE" 2>/dev/null | cut -d= -f2-)

    echo
    echo "=============================================================="
    echo "  网站前面有没有 CDN / 反向代理？"
    echo "=============================================================="
    echo "  1) 有，且源站 80/443 已只放行给 CDN   → 信任所有代理（*）"
    echo "  2) 有，源站端口对公网开放            → 需要填 CDN 回源 IP 段"
    echo "  3) 没有，访客直连这台服务器          → 留空"
    echo
    echo "  提醒：选 1 而端口又没做限制的话，任何人直连源站 IP 就能伪造自己的"
    echo "  地址，绕过全部限流和黑名单。这种情况请选 2。"
    echo
    [ -n "$CURRENT_TP" ] && echo "  当前值：$CURRENT_TP" && echo

    printf "请选择 [1-3，直接回车保持当前设置]: "
    read -r tp_answer || tp_answer=""
    case "$tp_answer" in
        1) set_env TRUSTED_PROXIES '*' ;;
        2)
            printf "请输入 CDN 回源 IP 段（逗号分隔，如 1.2.3.0/24,5.6.7.0/24）: "
            read -r tp_list || tp_list=""
            case "$tp_list" in
                '') echo "未填写，保持当前设置。按 IP 的限流在修好之前都不会生效。" ;;
                *) set_env TRUSTED_PROXIES "$tp_list" ;;
            esac
            ;;
        3) set_env TRUSTED_PROXIES '' ;;
        *) ;;   # 回车或乱输：不动，避免把已经配好的值清掉
    esac
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
FINAL_TP=$(grep '^TRUSTED_PROXIES=' "$ENV_FILE" 2>/dev/null | cut -d= -f2-)
if [ -n "$FINAL_TP" ]; then
    printf "  信任的代理        : %s\n" "$FINAL_TP"
else
    printf "  信任的代理        : (空) —— 若前面有 CDN，按 IP 的限流和黑名单不生效\n"
fi
echo
echo "  生效："
echo "    docker compose up -d"
echo
echo "  想换一档随时重跑这个脚本；也可以直接改 .env 里的数值。"
echo
