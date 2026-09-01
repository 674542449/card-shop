#!/usr/bin/env bash
#
# 一键更新：拉代码、按需重建、重启、验证，全部自动判断。
#
# 用法：
#   ./scripts/update.sh            检查 -> 拉取 -> 重启 -> 验证（会先问一次）
#   ./scripts/update.sh --check    只告诉你会发生什么，不动任何东西
#   ./scripts/update.sh --yes      不询问，直接执行（无人值守用）
#   ./scripts/update.sh --rollback 回滚到上一次更新前的版本
#
# 退出码：0 = 成功；1 = 有警告（更新完成了但有需要你看一眼的项）；2 = 失败。
#
# 这个脚本存在的理由，是 DEPLOY.md 里那套步骤有几个「不做就静默出错」的判断，
# 每次靠人记不现实：
#
#   · `docker compose up -d --build` 在镜像 ID 没变时**不会重建容器**，于是 entrypoint
#     的 migrate / view:cache / 编译产物预检一条都不跑，而命令返回 0、日志一片安静。
#     所以除了 up -d，还必须显式 restart app。
#   · docker/php/entrypoint.sh 是构建时 COPY 进镜像的，容器跑的是镜像里那一份 ——
#     它变了就必须 build，光 restart 不生效，而且同样没有任何报错。
#   · composer.lock 是被跟踪的文件，而 entrypoint 在 composer install 失败时会兜底跑
#     composer update 改写它。一旦变脏，git pull 直接 abort。
#   · nginx 启动时把 app:9000 解析成固定 IP，app 容器一旦被重建就换 IP —— 表现是
#     全站 502，而 docker compose ps 里所有容器都是 Up。
#   · 验证必须抓页面内容。Laravel 的 500 错误页也是一个完整的 HTML 页面，
#     只看 HTTP 状态码分不出它和正常页面。
#
# 不用 set -e：每一步都显式判断返回值并给出可执行的下一步，比在半路静默退出有用。

# 动作和「免确认」是两个独立的维度：--rollback --yes 要能一起用。
# 之前它们共用一个 MODE 变量，后写的那个会把前面的覆盖掉。
ACTION=update
ASSUME_YES=0
for a in "$@"; do
    case "$a" in
        --check)    ACTION=check ;;
        --rollback) ACTION=rollback ;;
        --yes|-y)   ASSUME_YES=1 ;;
        -h|--help)  sed -n '2,12p' "$0" | sed 's/^#\{1,\} \{0,1\}//'; exit 0 ;;
        *) echo "未知参数：$a（可用 --check / --yes / --rollback / --help）"; exit 2 ;;
    esac
done

# 询问一次。--yes 时直接放行；没有可用的 tty 时（cron、CI）也直接放行，
# 否则 read 会读到 EOF 当成「否」，脚本表现为「什么都没干就退出了」。
confirm() {
    [ "$ASSUME_YES" = "1" ] && return 0
    [ -t 0 ] || [ -e /dev/tty ] || return 0
    printf "  %s [y/N] " "$1"
    local a=""
    read -r a < /dev/tty 2>/dev/null || return 0
    case "$a" in y|Y|yes|YES) return 0 ;; *) return 1 ;; esac
}

RED=$'\033[31m'; GRN=$'\033[32m'; YEL=$'\033[33m'; DIM=$'\033[2m'; BLD=$'\033[1m'; RST=$'\033[0m'
WARNS=0

ok()   { echo "  ${GRN}+${RST} $1"; return 0; }
warn() { WARNS=$((WARNS+1)); echo "  ${YEL}!${RST} $1"; [ -n "${2:-}" ] && echo "      ${DIM}${2}${RST}"; return 0; }
note() { echo "      ${DIM}$1${RST}"; return 0; }
sect() { echo ""; echo "${BLD}$1${RST}"; return 0; }
die()  {
    echo ""
    echo "${RED}更新中止：$1${RST}"
    [ -n "${2:-}" ] && echo "${DIM}${2}${RST}"
    if [ -f "$ROLLBACK_FILE" ]; then
        echo ""
        echo "回滚到更新前的版本："
        echo "  ${DIM}./scripts/update.sh --rollback${RST}"
    fi
    exit 2
}

cd "$(dirname "$0")/.." || { echo "找不到仓库目录"; exit 2; }
ROLLBACK_FILE="storage/.update-rollback"

# .env 的值可能带引号，也可能是 CRLF 换行（在 Windows 上编辑过就会）。直接
# grep | cut 会把引号和回车一起传给下游命令，pg_dump 会因此连不上库。
# 用 tr 删回车、sed 去掉成对的引号 —— 比在 shell 里堆参数展开好读也好改。
getenv() {
    grep -E "^$1=" .env 2>/dev/null \
        | head -1 \
        | cut -d= -f2- \
        | tr -d '\r' \
        | sed -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'\$/\1/"
}

# docker compose 有 v1（docker-compose）和 v2（docker compose）两种调用方式。
DC=""
if docker compose version >/dev/null 2>&1; then DC="docker compose"
elif docker-compose version >/dev/null 2>&1; then DC="docker-compose"
fi

# ---------------------------------------------------------------- 回滚
if [ "$ACTION" = "rollback" ]; then
    sect "回滚"
    [ -f "$ROLLBACK_FILE" ] || die "没有找到回滚点（$ROLLBACK_FILE）" "只有用本脚本更新过一次之后才会记录回滚点。"
    TARGET=$(cat "$ROLLBACK_FILE")
    echo "  将回到 ${BLD}${TARGET}${RST}  $(git log -1 --format=%s "$TARGET" 2>/dev/null | cut -c1-60)"
    confirm "确认回滚？" || { echo "  已取消"; exit 0; }
    git reset --hard "$TARGET" || die "git reset 失败"
    [ -n "$DC" ] && $DC restart app nginx
    ok "已回滚到 $TARGET"
    exit 0
fi

# ---------------------------------------------------------------- 前置检查
sect "1/5  前置检查"

[ -d .git ] || die "这里不是一个 git 仓库" "请在 card-shop 目录下运行本脚本。"
[ -n "$DC" ] || die "找不到 docker compose" "装了 docker 吗？试试 docker compose version"
[ -f .env ] || warn ".env 不存在" "首次部署请先跑 ./install.sh，本脚本只负责更新已部署的站点。"

# 已跟踪文件不能有未提交的改动，否则 git pull 会 abort，而命令串起来时你只会看到
# 一行报错。
#
# 只看已跟踪文件（--untracked-files=no）：未跟踪文件不会阻止 git pull，除非拉下来的
# 提交正好要新建同名文件（那种情况 git pull 自己会报清楚）。而且这个脚本自己就会
# 产生未跟踪文件——数据库备份和回滚点——一律拦截的话，第二次更新会被自己挡住。
DIRTY=$(git status --porcelain --untracked-files=no)
if [ -n "$DIRTY" ]; then
    echo "${RED}工作区有未提交的改动：${RST}"
    echo "$DIRTY" | sed 's/^/      /'
    echo ""
    echo "  ${DIM}常见原因：entrypoint 在 composer install 失败时会兜底跑 composer update，${RST}"
    echo "  ${DIM}把被跟踪的 composer.lock 改脏。确认这些改动可以丢弃后：${RST}"
    echo "      git checkout -- ."
    die "工作区不干净，git pull 会失败"
fi
ok "工作区干净"

BRANCH=$(git rev-parse --abbrev-ref HEAD)
BEFORE=$(git rev-parse HEAD)
ok "当前分支 ${BRANCH}，版本 $(git rev-parse --short HEAD)"

git fetch origin "$BRANCH" --quiet 2>/dev/null || die "git fetch 失败" "检查网络和 git 凭据。"
AFTER=$(git rev-parse "origin/${BRANCH}")

if [ "$BEFORE" = "$AFTER" ]; then
    ok "已经是最新版本，无需更新"
    exit 0
fi

# ---------------------------------------------------------------- 分析
sect "2/5  这次会更新什么"

COUNT=$(git rev-list --count "${BEFORE}..${AFTER}")
echo "  ${COUNT} 个提交："
git log --oneline "${BEFORE}..${AFTER}" | sed 's/^/      /' | head -20
[ "$COUNT" -gt 20 ] && note "（只列了前 20 个）"

CHANGED=$(git diff --name-only "${BEFORE}..${AFTER}")

# 需要重建镜像吗
NEED_BUILD=0
BUILD_WHY=""
if echo "$CHANGED" | grep -qE '^docker/|^Dockerfile'; then
    NEED_BUILD=1; BUILD_WHY="docker/ 有改动"
fi
if echo "$CHANGED" | grep -qE '^composer\.(json|lock)$'; then
    NEED_BUILD=1; BUILD_WHY="${BUILD_WHY:+$BUILD_WHY，}composer 依赖有改动"
fi

# compose 配置变了要 up -d 而不是只 restart
NEED_UP=0
echo "$CHANGED" | grep -qE '^docker-compose\.ya?ml$' && NEED_UP=1

# 新迁移
MIGRATIONS=$(echo "$CHANGED" | grep '^database/migrations/' || true)
NEED_BACKUP=0
[ -n "$MIGRATIONS" ] && NEED_BACKUP=1

# .env.example 变了，提醒人工比对
ENV_CHANGED=0
echo "$CHANGED" | grep -q '^\.env\.example$' && ENV_CHANGED=1

echo ""
if [ "$NEED_BUILD" = "1" ]; then
    echo "  ${YEL}需要重建镜像${RST}（${BUILD_WHY}）"
    note "entrypoint.sh 是构建时 COPY 进镜像的，不 build 的话跑的还是旧脚本，而且没有任何报错。"
else
    ok "不需要重建镜像（只改了应用代码，走 bind mount）"
fi
if [ -n "$MIGRATIONS" ]; then
    echo "  ${YEL}有新的数据库迁移${RST}："
    echo "$MIGRATIONS" | sed 's/^/      /'
    note "更新前会自动备份数据库。"
else
    ok "没有新的数据库迁移"
fi
if [ "$ENV_CHANGED" = "1" ]; then
    warn ".env.example 有改动" "更新后请比对一下你的 .env 是否缺了新配置项：git diff ${BEFORE}..${AFTER} -- .env.example"
fi

if [ "$ACTION" = "check" ]; then
    echo ""
    echo "${DIM}（--check 模式，什么都没改）${RST}"
    exit 0
fi

echo ""
confirm "开始更新？" || { echo "  已取消"; exit 0; }

# ---------------------------------------------------------------- 执行
sect "3/5  更新"

echo "$BEFORE" > "$ROLLBACK_FILE"
ok "回滚点已记录（$(git rev-parse --short "$BEFORE")）"

if [ "$NEED_BACKUP" = "1" ]; then
    mkdir -p storage/backups
    BK="storage/backups/backup-$(date +%F-%H%M%S).sql"
    if $DC exec -T postgres pg_dump -U "$(grep -E '^DB_USERNAME=' .env | cut -d= -f2)" --clean --if-exists \
        "$(grep -E '^DB_DATABASE=' .env | cut -d= -f2)" > "$BK" 2>/dev/null && [ -s "$BK" ]; then
        ok "数据库已备份到 ${BK}（$(du -h "$BK" | cut -f1)）"
    else
        rm -f "$BK"
        die "数据库备份失败" "有迁移要跑却备份不了，不能继续。先手动确认 postgres 容器是否正常。"
    fi
fi

# --quiet：这个仓库把后台 SPA 的构建产物也提交了，一次更新动辄一百多个文件重命名，
# 默认输出会把脚本自己的进度整个淹掉。要看改了什么，上面第 2 步已经列过提交了。
if ! git pull --ff-only --quiet origin "$BRANCH"; then
    die "git pull 失败" "如果提示 divergent branches，说明本地有远端没有的提交，需要人工处理。"
fi
ok "代码已更新到 $(git rev-parse --short HEAD)（$(git diff --name-only "$BEFORE"..HEAD | wc -l | tr -d ' ') 个文件）"

# 记下重启前的启动时间，用来证明容器真的重启了
APP_CID=$($DC ps -q app 2>/dev/null | head -1)
STARTED_BEFORE=""
[ -n "$APP_CID" ] && STARTED_BEFORE=$(docker inspect -f '{{.State.StartedAt}}' "$APP_CID" 2>/dev/null)

if [ "$NEED_BUILD" = "1" ]; then
    echo "  正在重建镜像（会慢一些）..."
    $DC up -d --build || die "docker compose up -d --build 失败"
    ok "镜像已重建，容器已按新镜像启动"
elif [ "$NEED_UP" = "1" ]; then
    $DC up -d || die "docker compose up -d 失败"
    ok "compose 配置已应用"
fi

# 无论上面走了哪条路，都显式重启 app：
# up -d 在镜像 ID 和配置都没变时不会重建容器，entrypoint 就不会重跑。
$DC restart app || die "重启 app 容器失败"
ok "app 容器已重启（entrypoint 会重跑迁移、view:cache 和编译预检）"

$DC restart nginx || warn "重启 nginx 失败" "如果站点 502，手动跑一次 $DC restart nginx"
ok "nginx 已重启（避免 app 换 IP 后 502）"

# ---------------------------------------------------------------- 验证
sect "4/5  验证"

sleep 3

APP_CID=$($DC ps -q app 2>/dev/null | head -1)
if [ -n "$APP_CID" ]; then
    STARTED_AFTER=$(docker inspect -f '{{.State.StartedAt}}' "$APP_CID" 2>/dev/null)
    if [ -n "$STARTED_BEFORE" ] && [ "$STARTED_BEFORE" = "$STARTED_AFTER" ]; then
        warn "app 容器的启动时间没变" "entrypoint 可能没有重跑。手动确认：$DC logs --tail=50 app"
    else
        ok "app 容器已重启（$STARTED_AFTER）"
    fi
else
    warn "找不到 app 容器" "$DC ps 看看"
fi

DOWN=$($DC ps --format '{{.Service}} {{.State}}' 2>/dev/null | grep -v 'running' || true)
if [ -n "$DOWN" ]; then
    warn "有容器不在运行状态：" "$DOWN"
else
    ok "所有容器都在运行"
fi

# 启动日志里的静默警告
LOG=$($DC logs --tail=200 app 2>&1 || true)
echo "$LOG" | grep -qi 'STALE BUNDLE' && warn "后台 SPA 产物过期" "提交产物和源码不一致，后台可能跑的是旧 JS。"
echo "$LOG" | grep -qi 'does not parse\|MIGRATIONS_FAILED' && warn "启动日志里有编译或迁移错误" "$DC logs --tail=80 app"

# 页面必须抓到内容。500 错误页也是完整 HTML，只看状态码分不出来。
APP_URL=$(grep -E '^APP_URL=' .env 2>/dev/null | cut -d= -f2- | tr -d '"'"'"' \r')
if [ -n "$APP_URL" ]; then
    HTML=$(curl -sS --max-time 20 "$APP_URL/" 2>/dev/null || true)
    if [ -z "$HTML" ]; then
        warn "取不到首页内容" "源站可能无法从自己访问自己（CF-only 防火墙就会这样）。请用浏览器打开 $APP_URL 确认。"
    elif echo "$HTML" | grep -q 'csrf-token'; then
        ok "首页正常渲染（抓到 csrf-token，说明不是错误页）"

        # 页面里的资源版本号必须等于容器里文件的真实 mtime，否则拿到的是缓存的旧页面
        WEB_V=$(echo "$HTML" | grep -oE 'css/front\.css\?v=[0-9]+' | head -1 | grep -oE '[0-9]+$')
        FS_V=$($DC exec -T app php -r 'echo @filemtime("public/css/front.css");' 2>/dev/null | tr -d '\r')
        if [ -n "$WEB_V" ] && [ -n "$FS_V" ]; then
            if [ "$WEB_V" = "$FS_V" ]; then
                ok "页面拿到的是新版资源（?v=$WEB_V 与容器内 mtime 一致）"
            else
                warn "页面上的资源版本与容器里的文件对不上" "页面 ?v=$WEB_V，容器内 mtime=$FS_V —— 你看到的可能是缓存的旧页面。"
            fi
        fi
    else
        warn "首页抓不到 csrf-token" "可能是错误页。$DC logs --tail=80 app"
    fi
else
    warn ".env 里没有 APP_URL，跳过站点验证"
fi

# ---------------------------------------------------------------- 收尾
sect "5/5  完成"

echo "  ${BLD}$(git rev-parse --short "$BEFORE") -> $(git rev-parse --short HEAD)${RST}（${COUNT} 个提交）"
echo ""
echo "  ${DIM}后台管理员需要重新登录一次 —— 会话与密码哈希绑定后，旧会话一律失效。${RST}"
echo "  ${DIM}静态资源的 ?v= 跟着文件 mtime 走，不需要清 Cloudflare 缓存。${RST}"
echo "  ${DIM}出问题回滚：./scripts/update.sh --rollback${RST}"

if [ "$WARNS" -gt 0 ]; then
    echo ""
    echo "  ${YEL}有 ${WARNS} 项警告，请看上面标 ! 的行。${RST}"
    exit 1
fi

echo ""
echo "  ${GRN}更新完成，没有警告。${RST}"
exit 0
