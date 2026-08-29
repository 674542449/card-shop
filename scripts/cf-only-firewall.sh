#!/usr/bin/env bash
#
# 只放行 Cloudflare 回源网段访问源站的 80/443，其余一律丢弃。
#
# 为什么需要它：docker-compose.yml 把 80/443 发布在 0.0.0.0，而 Docker 是直接往
# nat 表写规则的 —— ufw / firewalld 的普通规则完全拦不住。不做这一步，任何人拿到
# 源站 IP 就能绕开 Cloudflare 的 WAF、限速和 Bot 防护直连，CDN 等于白挂。
#
# ---------------------------------------------------------------------------
# 这个脚本的三条硬边界，任何时候都不要突破：
#   1. 只操作 DOCKER-USER 链，从不触碰 INPUT。SSH 走 INPUT，所以本脚本不会锁死 22。
#   2. 从不执行任何 -F / -X / -P 全局操作。只清空自己建的 CF_ONLY 链。
#   3. 出错就硬失败并说明原因，绝不用 || true 静默跳过 —— 「看起来成功、实际没保护」
#      是最糟的失败模式。
# ---------------------------------------------------------------------------
#
# 最容易踩的那个坑（真实发生过，值得单独说）：
#   DOCKER-USER 挂在 FORWARD 链上，容器**出站**流量同样经过它。跳转规则如果写成
#   -p tcp --dports 80,443 -j CF_ONLY 而不限定入口网卡，容器出站包的源 IP 是
#   172.x.x.x，不在 CF 网段内，会被一起 DROP。表现是：USDT 下单 cURL error 28
#   超时、Turnstile 验证失败、Telegram 通知和 SEO 推送全部中断，而入站看起来一切
#   正常，几乎没人会联想到防火墙。所以跳转规则必须带 -i 外网网卡，并且 CF_ONLY
#   链第一条必须放行 ESTABLISHED,RELATED。
#
# 用法：
#   sudo ./scripts/cf-only-firewall.sh --check  --domain=example.com   只体检，不改规则
#   sudo ./scripts/cf-only-firewall.sh --apply  --domain=example.com   应用规则
#   sudo ./scripts/cf-only-firewall.sh --apply  --domain=example.com --persist
#   sudo ./scripts/cf-only-firewall.sh --status                        看当前生效的规则
#   sudo ./scripts/cf-only-firewall.sh --remove                        撤销
#
# 可选参数：
#   --iface=ens3     手工指定外网网卡（自动探测不对时用）
#   --ports=80,443   要保护的端口（默认 80,443，注意匹配的是容器侧端口）
#   --offline        不联网拉取 CF 网段，用脚本内置的兜底列表
#   --yes            跳过交互确认

set -euo pipefail

CHAIN="CF_ONLY"
MODE=""
DOMAIN=""
IFACE=""
PORTS="80,443"
OFFLINE=0
PERSIST=0
ASSUME_YES=0
WAN=""
V6_MODE="skip"

RED=$'\033[31m'
GRN=$'\033[32m'
YEL=$'\033[33m'
DIM=$'\033[2m'
RST=$'\033[0m'

ok()    { printf "  %s+%s %s\n" "$GRN" "$RST" "$*"; }
warn()  { printf "  %s!%s %s\n" "$YEL" "$RST" "$*"; }
info()  { printf "  %s\n" "$*"; }
head2() { printf "\n%s-- %s --%s\n" "$DIM" "$*" "$RST"; }
die()   { printf "\n  %sx %s%s\n\n" "$RED" "$*" "$RST" >&2; exit 1; }

confirm() {
    printf "\n  %s [y/N] " "$1"
    read -r a || a=""
    case "$a" in
        y|Y|yes|YES) : ;;
        *) die "已取消，未改动任何规则。" ;;
    esac
}

# ---------------------------------------------------------------- 参数
for arg in "$@"; do
    case "$arg" in
        --check)    MODE="check" ;;
        --apply)    MODE="apply" ;;
        # 供 systemd 开机调用：装规则，但不做任何「需要站点已在服务」的验证。
        --boot)     MODE="boot" ;;
        --remove)   MODE="remove" ;;
        --status)   MODE="status" ;;
        --persist)  PERSIST=1 ;;
        --offline)  OFFLINE=1 ;;
        --yes)      ASSUME_YES=1 ;;
        --domain=*) DOMAIN="${arg#*=}" ;;
        --iface=*)  IFACE="${arg#*=}" ;;
        --ports=*)  PORTS="${arg#*=}" ;;
        -h|--help)  sed -n '2,40p' "$0" | sed 's/^#\{1,\} \{0,1\}//'; exit 0 ;;
        *) die "未知参数：$arg（--help 看用法）" ;;
    esac
done
[ -n "$MODE" ] || die "必须指定 --check / --apply / --remove / --status 之一"

# ---------------------------------------------------------------- 环境前置检查
# 每一项都是硬失败。宁可什么都不做，也不要做一半。
preflight() {
    head2 "环境检查"

    # 容器内绝不能跑：镜像里没有 iptables，也没有 NET_ADMIN；若有人用 --privileged
    # 跑，改坏的是宿主机规则，事后极难回溯。
    if [ -f /.dockerenv ] || grep -qE '(docker|containerd)' /proc/1/cgroup 2>/dev/null; then
        die "检测到容器环境。这个脚本必须在宿主机上运行，不是在容器里。"
    fi
    ok "运行在宿主机上"

    [ "$(id -u)" = "0" ] || die "需要 root。请用 sudo 运行。"
    ok "以 root 运行"

    command -v iptables >/dev/null 2>&1 \
        || die "找不到 iptables。这台机器可能是纯 nftables，请安装 iptables-nft 兼容包，或手工配置。"
    backend="$(iptables --version 2>/dev/null | grep -o 'nf_tables\|legacy' || echo unknown)"
    ok "iptables 可用（后端：$backend）"

    if command -v firewall-cmd >/dev/null 2>&1 && firewall-cmd --state >/dev/null 2>&1; then
        warn "firewalld 正在运行。它每次 reload 或包更新都可能重建链、冲掉这里的规则。"
        warn "建议要么停用 firewalld，要么改用 firewalld 自己的 rich rule 做同样的事。"
        [ "$ASSUME_YES" = "1" ] || confirm "仍然继续？"
    fi

    command -v docker >/dev/null 2>&1 || die "找不到 docker。"
    # DOCKER-USER 由 dockerd 创建。它不存在说明 docker 没起来，或这台机器的 Docker
    # 没在用 iptables（daemon.json 里 iptables:false）。两种情况都不能继续。
    iptables -n -L DOCKER-USER >/dev/null 2>&1 \
        || die "DOCKER-USER 链不存在。请确认 dockerd 已启动、且未设置 iptables:false。"
    ok "DOCKER-USER 链存在"
}

# ---------------------------------------------------------------- 外网网卡
# 自动探测不可靠：Oracle Cloud 常是 ens3/enp0s3 而不是 eth0；多网卡、bond，以及
# 后装的 WireGuard / Cloudflare Tunnel 都会改变默认路由。所以探测结果一定打印出来
# 让人确认，并且显式拒绝落到 lo / docker* / br-* 上 —— 那等于把容器互访和出站
# 全部纳入 DROP 范围。
detect_iface() {
    local i
    if [ -n "$IFACE" ]; then
        i="$IFACE"
        ip link show "$i" >/dev/null 2>&1 || die "指定的网卡 $i 不存在。"
    else
        i="$(ip -o route get 1.1.1.1 2>/dev/null | sed -n 's/.* dev \([^ ]*\).*/\1/p' | head -1)"
        [ -n "$i" ] || die "无法探测外网网卡。请用 --iface=网卡名 手工指定。"
    fi
    case "$i" in
        lo|docker*|br-*|veth*)
            die "探测到的网卡是 $i，这是本机或容器网卡，用它会切断容器网络。请用 --iface= 指定真正的外网网卡。" ;;
    esac
    WAN="$i"
    ok "外网网卡：$WAN  $(ip -4 -o addr show "$WAN" 2>/dev/null | awk '{print $4}' | tr '\n' ' ')"
}

# ---------------------------------------------------------------- 端口一致性
# DOCKER-USER 在 FORWARD 链上看到的是 DNAT 之后的包：目的端口是容器侧端口。
# 现在能用 80/443 匹配，纯粹是因为 compose 映射成 80:80 和 443:443 恰好相等。
# 有人改成 8080:80 之后，按宿主端口写的规则就永远匹配不到 —— 又一次静默 fail-open。
check_ports() {
    local dir="$1" mapped host cont p
    if [ ! -f "$dir/docker-compose.yml" ]; then
        warn "找不到 docker-compose.yml，跳过端口一致性校验。"
        return 0
    fi
    for p in ${PORTS//,/ }; do
        mapped="$(grep -oE "\"${p}:[0-9]+\"" "$dir/docker-compose.yml" | head -1 || true)"
        if [ -z "$mapped" ]; then
            warn "compose 里没找到宿主端口 $p 的映射，请确认 --ports 是否正确。"
            continue
        fi
        mapped="${mapped//\"/}"
        host="${mapped%%:*}"
        cont="${mapped##*:}"
        [ "$host" = "$cont" ] \
            || die "端口映射是 ${host}:${cont}（宿主:容器）。本脚本匹配的是容器侧端口，请改用 --ports=${cont}。"
    done
    ok "端口映射一致（匹配容器侧端口 $PORTS）"
}

# ---------------------------------------------------------------- CF 网段
CF4_FALLBACK="173.245.48.0/20 103.21.244.0/22 103.22.200.0/22 103.31.4.0/22 141.101.64.0/18 108.162.192.0/18 190.93.240.0/20 188.114.96.0/20 197.234.240.0/22 198.41.128.0/17 162.158.0.0/15 104.16.0.0/13 104.24.0.0/14 172.64.0.0/13 131.0.72.0/22"
CF6_FALLBACK="2400:cb00::/32 2606:4700::/32 2803:f800::/32 2405:b500::/32 2405:8100::/32 2a06:98c0::/29 2c0f:f248::/32"

# 拉取失败若返回空列表，生成的就是一条「谁都不放行」的纯 DROP 规则集 —— 整站下线。
# 所以条数低于阈值一律判定为失败，退回内置列表。
fetch_cf() {
    local url="$1" fallback="$2" min="$3" out n
    if [ "$OFFLINE" = "1" ]; then printf '%s' "$fallback"; return 0; fi
    out="$(curl -fsS --max-time 15 "$url" 2>/dev/null | tr -d '\r' | grep -E '^[0-9a-fA-F.:]+/[0-9]+$' || true)"
    n="$(printf '%s\n' "$out" | grep -c . || true)"
    if [ "${n:-0}" -lt "$min" ]; then
        warn "从 $url 拉取网段失败或条数异常（$n 条），改用脚本内置的兜底列表。"
        printf '%s' "$fallback"
    else
        printf '%s' "$out"
    fi
}

# 纯 bash 的 IPv4 CIDR 判断，避免依赖 ipcalc / python
ip_in_cidr() {
    local ip="$1" cidr="$2" net bits a b c d n1 n2 n3 n4 ipnum netnum mask
    net="${cidr%/*}"; bits="${cidr#*/}"
    IFS=. read -r a b c d <<< "$ip"
    IFS=. read -r n1 n2 n3 n4 <<< "$net"
    case "${a}${b}${c}${d}${n1}${n2}${n3}${n4}" in *[!0-9]*|'') return 1 ;; esac
    ipnum=$(( (a<<24) + (b<<16) + (c<<8) + d ))
    netnum=$(( (n1<<24) + (n2<<16) + (n3<<8) + n4 ))
    [ "$bits" -eq 0 ] && return 0
    mask=$(( (0xFFFFFFFF << (32 - bits)) & 0xFFFFFFFF ))
    [ $(( ipnum & mask )) -eq $(( netnum & mask )) ]
}

# ---------------------------------------------------------------- 域名确实经过 CF？
# 这是最重要的一道闸：在没挂 CF 的机器上应用 CF-only，等于把 80/443 对全世界
# （包括你自己）DROP。而排查时 ufw status 是空的、docker ps 是正常的，规则藏在
# DOCKER-USER 链里，几乎不可能自行定位。所以拿不到证据就拒绝执行。
# 第二个参数是**实时拉取**的 CF 网段。早先这里直接用脚本内置的 CF4_FALLBACK，而真正的
# 拉取在它之后才发生 —— Cloudflare 新增网段后，域名明明开着橙云，脚本却判定「不在 CF
# 网段内」并拒绝执行，还反过来让用户去面板打开本来就开着的橙云。
verify_behind_cf() {
    local d="$1" cf_list="${2:-$CF4_FALLBACK}" ips ip cidr inside=0 code
    [ -n "$d" ] || die "--apply 必须同时给出 --domain=你的域名，用来验证站点确实挂在 Cloudflare 后面。"

    head2 "验证 $d 确实在 Cloudflare 后面"
    ips="$(getent ahosts "$d" 2>/dev/null | awk '{print $1}' | sort -u || true)"
    [ -n "$ips" ] || die "$d 解析不到任何 IP。"

    for ip in $ips; do
        case "$ip" in *:*) continue ;; esac
        for cidr in $cf_list; do
            if ip_in_cidr "$ip" "$cidr"; then inside=1; break 2; fi
        done
    done
    if [ "$inside" != "1" ]; then
        die "$d 解析到的 IP（$(echo $ips | tr '\n' ' ')）不在 Cloudflare 网段内。
  说明这个域名还没开启橙云代理。现在应用规则会把源站对所有人封死，包括 Cloudflare 自己。
  请先在 Cloudflare 面板把 A 记录的代理状态改成「已代理」（橙色云朵），再回来运行。"
    fi
    ok "$d 解析到 Cloudflare 网段"

    code="$(curl -fsS -o /dev/null -w '%{http_code}' --max-time 20 "https://$d/" 2>/dev/null || echo 000)"
    case "$code" in
        2*|3*) ok "经 Cloudflare 访问 https://$d/ 返回 $code" ;;
        *) die "经 Cloudflare 访问 https://$d/ 返回 $code。站点现在就不通，先修好再加防火墙。" ;;
    esac
}

# ---------------------------------------------------------------- IPv6
# 只做 IPv4 会留下 v6 旁路：若宿主机有公网 v6 且 Docker 启用了 ip6tables，v6 直连
# 源站完全敞开，整个 CF-only 形同虚设。反过来，在 Docker 未接管 ip6tables 的机器上
# 照抄 ip6tables 命令则毫无效果，却会打印「已应用」让人误以为受保护。三种情况分开处理。
detect_v6() {
    V6_MODE="skip"
    if ip -6 addr show scope global 2>/dev/null | grep -q inet6; then
        if command -v ip6tables >/dev/null 2>&1 && ip6tables -n -L DOCKER-USER >/dev/null 2>&1; then
            V6_MODE="apply"
            ok "宿主机有公网 IPv6，Docker 的 ip6tables 已启用 —— 会一并防护 v6"
        else
            V6_MODE="warn"
            warn "宿主机有公网 IPv6，但 Docker 未接管 ip6tables。"
            warn "存在 IPv6 旁路：别人可以用 v6 直连源站绕过本规则。"
            warn "处理：在 /etc/docker/daemon.json 设 ip6tables:true 后重启 docker，或在云控制台限制 v6 入站。"
        fi
    else
        ok "宿主机没有公网 IPv6，跳过 v6 规则（无旁路风险）"
    fi
}

# ---------------------------------------------------------------- 应用
# 关键：专用链内一律用 -A 追加，让书写顺序等于生效顺序。
# 常见写法是对 DOCKER-USER 连续 -I 插入放行和 DROP —— 但 -I 插到链首，最后插入的
# 排最前，最终顺序恰好倒过来：DROP 跑到第一条，把 Cloudflare 也一起丢掉，站点 100%
# 下线。这个错误肉眼审阅时非常难发现，因为脚本里的书写顺序看上去完全正确。
build_chain() {
    local ipt="$1" cf_list="$2" c
    $ipt -N "$CHAIN" 2>/dev/null || true
    $ipt -F "$CHAIN"
    # 第 1 条必须是它：已建立的连接直接放行。否则握手成功、后续包被丢，表现是连接
    # 建上了却卡住、大响应体被截断、回调超时 —— 比彻底不通更难诊断。
    $ipt -A "$CHAIN" -m conntrack --ctstate ESTABLISHED,RELATED -j RETURN
    for c in $cf_list; do $ipt -A "$CHAIN" -s "$c" -j RETURN; done
    $ipt -A "$CHAIN" -j DROP
}

# DOCKER-USER 出厂就带着一条 -j RETURN。用 -A 追加的规则排在它后面，**永远执行不到** ——
# 脚本会一路打印成功、iptables -L 里规则也齐全，而防护是零，源站照样能被直连。这正是本文件
# 开头说的「看起来成功、实际没保护」，只不过发生在跳转这一层。所以两条都必须 -I 到最前面。
install_jump() {
    local ipt="$1"
    # 位置 1：任何不是从外网网卡进来的包一律 RETURN。这是容器出站的护身符 ——
    # 即使后面某条规则漏写了 -i，容器出站也不会被波及。
    $ipt -C DOCKER-USER ! -i "$WAN" -j RETURN 2>/dev/null \
        || $ipt -I DOCKER-USER 1 ! -i "$WAN" -j RETURN
    # 位置 2：紧随其后，仍然排在 Docker 自带的 RETURN 之前。
    $ipt -C DOCKER-USER -i "$WAN" -p tcp -m multiport --dports "$PORTS" -j "$CHAIN" 2>/dev/null \
        || $ipt -I DOCKER-USER 2 -i "$WAN" -p tcp -m multiport --dports "$PORTS" -j "$CHAIN"
}

# 断言跳转真的可达：它必须排在 DOCKER-USER 里任何一条「无条件 RETURN」之前。
# 只检查规则存在是不够的 —— 存在但排在 RETURN 之后，等于不存在。
assert_jump_reachable() {
    local ipt="$1" rules jump_at return_at
    rules="$($ipt -S DOCKER-USER)"
    jump_at="$(printf %s"\n" "$rules" | grep -n -- "-j $CHAIN" | head -1 | cut -d: -f1)"
    return_at="$(printf %s"\n" "$rules" | grep -n -E "^-A DOCKER-USER -j RETURN$" | head -1 | cut -d: -f1)"
    [ -n "$jump_at" ] || die "DOCKER-USER 里没有找到跳转到 $CHAIN 的规则，规则未生效。"
    # iptables -S 的第一行是 "-N DOCKER-USER" 链头，不是规则。减掉它，报出来的
    # 序号才和 iptables -L --line-numbers 对得上，否则排查的人会对着错位的数字找。
    jump_at=$((jump_at - 1))
    [ -n "$return_at" ] && return_at=$((return_at - 1))
    if [ -n "$return_at" ] && [ "$jump_at" -gt "$return_at" ]; then
        die "跳转规则排在 DOCKER-USER 第 $jump_at 条，而无条件 RETURN 在第 $return_at 条 —— 跳转永远执行不到，防护为零。
  请先运行 --remove 再重试。"
    fi
}

# 断言 ESTABLISHED 确实是链里的第 1 条规则。不满足说明链被别的东西改过，拒绝完成。
assert_chain_sane() {
    local ipt="$1"
    $ipt -S "$CHAIN" | sed -n '2p' | grep -q ESTABLISHED \
        || die "$CHAIN 链第 1 条不是 ESTABLISHED,RELATED 放行，规则顺序异常，已中止。请先 --remove 再重试。"
}

do_remove() {
    local ipt
    for ipt in iptables ip6tables; do
        command -v "$ipt" >/dev/null 2>&1 || continue
        $ipt -n -L DOCKER-USER >/dev/null 2>&1 || continue
        while $ipt -D DOCKER-USER -i "${WAN:-eth0}" -p tcp -m multiport --dports "$PORTS" -j "$CHAIN" 2>/dev/null; do :; done
        while $ipt -D DOCKER-USER ! -i "${WAN:-eth0}" -j RETURN 2>/dev/null; do :; done
        $ipt -F "$CHAIN" 2>/dev/null || true
    done
}

# 应用后必须验出站仍通。这正是之前那次故障的检出点：只验入站会漏掉它。
verify_outbound() {
    local dir="$1" code
    if ! docker compose -f "$dir/docker-compose.yml" ps --status running 2>/dev/null | grep -q app; then
        warn "app 容器未运行，跳过容器出站验证。启动后请务必手工验一次："
        info "  docker compose exec app curl -m 10 -so /dev/null -w '%{http_code}\\n' https://api.github.com"
        return 0
    fi
    code="$(docker compose -f "$dir/docker-compose.yml" exec -T app \
            curl -m 15 -so /dev/null -w '%{http_code}' https://api.github.com 2>/dev/null || echo 000)"
    case "$code" in
        2*|3*|4*) ok "容器出站正常（HTTP $code）" ;;
        *)
            printf "\n  %s容器出站被切断了（返回 %s）。正在自动回滚……%s\n" "$RED" "$code" "$RST"
            do_remove
            die "已回滚。出站不通说明跳转规则的 -i 网卡判断有误，请用 --iface= 指定正确的外网网卡后重试。" ;;
    esac
}

# 排序很关键：unit 若在 dockerd 之前跑，DOCKER-USER 链还不存在，规则装不上。
# 而 iptables 规则重启后不持久 —— 失效时站点访问一切正常、运维毫不知情，源站却
# 重新对全网暴露。这是最糟的失败模式，所以脚本在链不存在时是硬失败而非静默跳过。
install_unit() {
    local script_path="$1" extra=""
    [ -n "$IFACE" ] && extra=" --iface=$IFACE"
    cat > /etc/systemd/system/cf-only-firewall.service <<UNIT
[Unit]
Description=只放行 Cloudflare 网段访问 Docker 发布的 80/443
After=docker.service network-online.target
Requires=docker.service
Wants=network-online.target

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=${script_path} --boot --domain=${DOMAIN} --ports=${PORTS}${extra} --yes

[Install]
WantedBy=multi-user.target
UNIT
    systemctl daemon-reload
    systemctl enable cf-only-firewall.service >/dev/null 2>&1
    ok "已安装开机自启：cf-only-firewall.service"
    info "  查看：systemctl status cf-only-firewall"
}

# ---------------------------------------------------------------- 主流程
SELF="$(cd "$(dirname "$0")" && pwd)/$(basename "$0")"
REPO_DIR="$(cd "$(dirname "$0")/.." && pwd)"

case "$MODE" in
  status)
    preflight
    detect_iface
    head2 "DOCKER-USER 链"
    iptables -n -L DOCKER-USER --line-numbers
    head2 "$CHAIN 链（前几条）"
    iptables -n -L "$CHAIN" --line-numbers 2>/dev/null | head -8 || info "（未创建）"
    ;;

  remove)
    preflight
    detect_iface
    do_remove
    systemctl disable --now cf-only-firewall.service >/dev/null 2>&1 || true
    ok "已撤销。源站 $PORTS 恢复对公网开放。"
    ;;

  check)
    preflight
    detect_iface
    check_ports "$REPO_DIR"
    detect_v6
    head2 "拉取 Cloudflare 网段"
    CF4="$(fetch_cf https://www.cloudflare.com/ips-v4 "$CF4_FALLBACK" 10)"
    ok "IPv4 网段 $(printf %s"\n" $CF4 | grep -c .) 条"
    if [ -n "$DOMAIN" ]; then
        verify_behind_cf "$DOMAIN" "$CF4"
    else
        warn "未给 --domain，跳过 Cloudflare 验证（--apply 时必须提供）"
    fi
    head2 "结论"
    ok "环境满足条件，可以运行：sudo $0 --apply --domain=${DOMAIN:-你的域名}"
    ;;

  apply|boot)
    preflight
    detect_iface
    check_ports "$REPO_DIR"
    detect_v6

    head2 "拉取 Cloudflare 网段"
    CF4="$(fetch_cf https://www.cloudflare.com/ips-v4 "$CF4_FALLBACK" 10)"
    ok "IPv4 网段 $(printf %s"\n" $CF4 | grep -c .) 条"
    CF6=""
    if [ "$V6_MODE" = "apply" ]; then
        CF6="$(fetch_cf https://www.cloudflare.com/ips-v6 "$CF6_FALLBACK" 5)"
        ok "IPv6 网段 $(printf %s"\n" $CF6 | grep -c .) 条"
    fi

    # 网段拉完之后才验域名，而且用刚拉到的这一份。
    # --boot 跳过这一步：开机时容器还在起，站点必然还不通，拿它当前置条件会让规则
    # 装不上 —— 那意味着每次重启后源站都对全网裸奔，而且毫无迹象。
    if [ "$MODE" = "boot" ]; then
        info "开机模式：跳过站点可达性验证（此刻容器多半还没起来）。"
    else
        verify_behind_cf "$DOMAIN" "$CF4"
    fi

    if [ "$ASSUME_YES" != "1" ]; then
        printf "\n  即将：只允许 Cloudflare 网段访问 %s 上的 %s 端口，其余来源丢弃。\n" "$WAN" "$PORTS"
        printf "  不会触碰 INPUT 链，SSH(22) 不受影响。\n"
        confirm "确认应用？"
    fi

    head2 "应用规则"
    build_chain iptables "$CF4"
    install_jump iptables
    assert_chain_sane iptables
    assert_jump_reachable iptables
    ok "IPv4 规则已应用"
    if [ "$V6_MODE" = "apply" ]; then
        build_chain ip6tables "$CF6"
        install_jump ip6tables
        assert_chain_sane ip6tables
        assert_jump_reachable ip6tables
        ok "IPv6 规则已应用"
    fi

    if [ "$MODE" = "boot" ]; then
        head2 "开机模式：规则已装，跳过联网验证"
        info "容器起来后可随时复验：sudo $0 --status"
        exit 0
    fi

    head2 "应用后验证（两个方向都要验）"
    verify_outbound "$REPO_DIR"
    code="$(curl -fsS -o /dev/null -w '%{http_code}' --max-time 20 "https://$DOMAIN/" 2>/dev/null || echo 000)"
    case "$code" in
        2*|3*) ok "经 Cloudflare 入站正常（HTTP $code）" ;;
        *) warn "经 Cloudflare 访问返回 $code。若持续不通，执行 sudo $0 --remove 回滚。" ;;
    esac

    [ "$PERSIST" = "1" ] && install_unit "$SELF"

    head2 "生效的规则"
    iptables -n -L DOCKER-USER --line-numbers | head -6

    printf "\n  %s完成。%s还剩两件脚本做不到的事：\n" "$GRN" "$RST"
    info "1) 云控制台（Oracle 安全列表 / AWS 安全组）是独立的一层，也要放行 80/443，"
    info "   并建议同样只放行 Cloudflare 网段。"
    info "2) Cloudflare 网段一年会变几次。建议每月跑一次："
    info "   sudo $0 --apply --domain=$DOMAIN --yes"
    [ "$PERSIST" = "1" ] || info "另：iptables 规则重启后不持久，加 --persist 可安装开机自启。"
    ;;
esac
