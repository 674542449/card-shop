#!/usr/bin/env bash
#
# 一键更新：拉代码、按需重建、重启、验证，全部自动判断。
#
# 用法：
#   ./scripts/update.sh            检查 -> 拉取 -> 重启 -> 验证（会先问一次）
#   ./scripts/update.sh --check    只告诉你会发生什么，不动任何东西（可与下面几项组合）
#   ./scripts/update.sh --yes      不询问，直接执行（无人值守用）
#   ./scripts/update.sh --rollback 回滚到上一次更新前的版本
#   ./scripts/update.sh --redeploy 不拉代码，只重启容器并验证
#   ./scripts/update.sh --build    强制重建镜像（可与上面几项组合）
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
FORCE_BUILD=0
DRY_RUN=0
for a in "$@"; do
    case "$a" in
        --check)    DRY_RUN=1 ;;
        --rollback) ACTION=rollback ;;
        --redeploy) ACTION=redeploy ;;
        --build)    FORCE_BUILD=1 ;;
        --yes|-y)   ASSUME_YES=1 ;;
        -h|--help)  sed -n '2,14p' "$0" | sed 's/^#\{1,\} \{0,1\}//'; exit 0 ;;
        *) echo "未知参数：$a（可用 --check / --yes / --rollback / --redeploy / --build / --help）"; exit 2 ;;
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
    if [ "$DRY_RUN" = "1" ]; then
        echo ""
        echo "${DIM}（--check 模式，什么都没改）${RST}"
        exit 0
    fi
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
    # redeploy 不跑 git pull，脏工作区拦不住它。而「上次部署跑了一半」正是
    # redeploy 要救的场景之一 —— 那时 entrypoint 的兜底 composer update 很可能
    # 已经把 composer.lock 改脏了。在这一档拦下来，等于恰好在需要它的时候不可用；
    # 更糟的是提示里的 git checkout -- . 会把人正在跑的手工热修一起抹掉。
    if [ "$ACTION" = "redeploy" ]; then
        warn "工作区不干净，但 --redeploy 不拉代码，继续" "这些改动会原样保留。"
    else
        echo "  ${DIM}常见原因：entrypoint 在 composer install 失败时会兜底跑 composer update，${RST}"
        echo "  ${DIM}把被跟踪的 composer.lock 改脏。确认这些改动可以丢弃后：${RST}"
        echo "      git checkout -- ."
        die "工作区不干净，git pull 会失败"
    fi
else
    ok "工作区干净"
fi

BRANCH=$(git rev-parse --abbrev-ref HEAD)
BEFORE=$(git rev-parse HEAD)
ok "当前分支 ${BRANCH}，版本 $(git rev-parse --short HEAD)"

AFTER=""
if git fetch origin "$BRANCH" --quiet 2>/dev/null; then
    AFTER=$(git rev-parse "origin/${BRANCH}")
elif [ "$ACTION" = "redeploy" ]; then
    # redeploy 只用磁盘上已有的代码，够不到远端不影响它干活。而「远端够不到」
    # 往往正是要 redeploy 的当口，在这里 die 等于把救援工具锁在门外。
    warn "git fetch 失败，但 --redeploy 不需要远端，继续" "本次不会比对远端版本。"
else
    die "git fetch 失败" "检查网络和 git 凭据。"
fi

# 早退条件必须同时排掉 --build：--build 的典型场景就是「代码没变，但要重建镜像」
# （基础镜像更新了、上次 build 中途失败、docker/ 是手工 pull 的）。只看 ACTION
# 的话，--build 会在这里被静默丢掉，人拿到 exit 0 以为重建过了。
if [ -n "$AFTER" ] && [ "$BEFORE" = "$AFTER" ] && [ "$ACTION" != "redeploy" ] && [ "$FORCE_BUILD" != "1" ]; then
    ok "已经是最新版本，无需更新"
    note "只想让站点按当前代码重新加载一遍（上次部署没跑完、或代码是手工 pull 的），用："
    note "  ./scripts/update.sh --redeploy"
    note "改过 .env 或只想重建镜像，用："
    note "  ./scripts/update.sh --redeploy --build"
    exit 0
fi

# ---------------------------------------------------------------- 分析
sect "2/5  这次会更新什么"

# redeploy 不拉代码，没有「这次改了什么」可分析。但 CHANGED 留空不等于「什么都
# 不用做」—— 那几个自动判断是从 diff 推出来的，没有 diff 就推不出来，只能按最坏
# 情况处理：
#   · 一律 up -d 而不是只 restart。restart 复用现有容器，不会重读 docker-compose.yml，
#     也不会重新展开其中的 ${...}（PHP_FPM_*、TLS_CERT_DIR、POSTGRES_* 这些）。
#     scripts/doctor.sh 里就写着这条，并且开的药方正是 docker compose up -d。
#   · 一律先备份数据库。restart app 会重跑 entrypoint，而 entrypoint 无条件执行
#     php artisan migrate --force —— 「代码是手工 pull 的」「上次部署跑了一半」
#     这两种 redeploy 场景，磁盘上很可能正躺着没跑过的迁移。
# 判断依据是「这一趟到底会不会拉到新提交」，而不是用了哪个 flag。
# --redeploy 不拉；--build 在已经是最新时也不拉（它是被上面那个早退条件放行进来的）。
# 两种情况都没有 diff 可查，所以都得按最坏情况处理。
NO_PULL=0
[ "$ACTION" = "redeploy" ] && NO_PULL=1
[ -n "$AFTER" ] && [ "$BEFORE" = "$AFTER" ] && NO_PULL=1

if [ "$NO_PULL" = "1" ]; then
    COUNT=0
    CHANGED=""
    NEED_UP_FORCED=1
    NEED_BACKUP_FORCED=1
    if [ "$ACTION" = "redeploy" ]; then
        echo "  ${DIM}--redeploy：不拉取新代码，只重新应用配置、重启并验证。${RST}"
    else
        echo "  ${DIM}代码已经是最新，本次只重新应用配置并重建/重启。${RST}"
    fi
    if [ -n "$AFTER" ] && [ "$BEFORE" != "$AFTER" ]; then
        warn "远端还有 $(git rev-list --count "${BEFORE}..${AFTER}") 个提交没拉"              "本次不会拉取。要更新代码请去掉 --redeploy。"
    fi
else
    NEED_UP_FORCED=0
    NEED_BACKUP_FORCED=0
    COUNT=$(git rev-list --count "${BEFORE}..${AFTER}")
    echo "  ${COUNT} 个提交："
    git log --oneline "${BEFORE}..${AFTER}" | sed 's/^/      /' | head -20
    [ "$COUNT" -gt 20 ] && note "（只列了前 20 个）"

    CHANGED=$(git diff --name-only "${BEFORE}..${AFTER}")
fi

# 需要重建镜像吗
NEED_BUILD=0
BUILD_WHY=""
if echo "$CHANGED" | grep -qE '^docker/|^Dockerfile'; then
    NEED_BUILD=1; BUILD_WHY="docker/ 有改动"
fi
if echo "$CHANGED" | grep -qE '^composer\.(json|lock)$'; then
    NEED_BUILD=1; BUILD_WHY="${BUILD_WHY:+$BUILD_WHY，}composer 依赖有改动"
fi
if [ "$FORCE_BUILD" = "1" ]; then
    NEED_BUILD=1; BUILD_WHY="${BUILD_WHY:+$BUILD_WHY，}--build 强制指定"
fi

# compose 配置变了要 up -d 而不是只 restart
NEED_UP=0
echo "$CHANGED" | grep -qE '^docker-compose\.ya?ml$' && NEED_UP=1
[ "$NEED_UP_FORCED" = "1" ] && NEED_UP=1

# 新迁移
MIGRATIONS=$(echo "$CHANGED" | grep '^database/migrations/' || true)
NEED_BACKUP=0
[ -n "$MIGRATIONS" ] && NEED_BACKUP=1
[ "$NEED_BACKUP_FORCED" = "1" ] && NEED_BACKUP=1

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
elif [ "$NEED_BACKUP_FORCED" = "1" ]; then
    # 这一档没有 diff 可查，所以不能断言「没有迁移」—— entrypoint 会无条件跑
    # migrate，磁盘上有没有待跑的迁移，这里根本不知道。
    echo "  ${YEL}无法判断有没有待跑的迁移${RST}（本次没拉新代码，没有差异可比对）"
    note "重启会触发 entrypoint 的 migrate，所以一律先备份数据库。"
else
    ok "没有新的数据库迁移"
fi
if [ "$ENV_CHANGED" = "1" ]; then
    warn ".env.example 有改动" "更新后请比对一下你的 .env 是否缺了新配置项：git diff ${BEFORE}..${AFTER} -- .env.example"
fi

if [ "$DRY_RUN" = "1" ]; then
    echo ""
    echo "${DIM}（--check 模式，什么都没改）${RST}"
    exit 0
fi

echo ""
if [ "$ACTION" = "redeploy" ]; then
    confirm "开始重启并验证？" || { echo "  已取消"; exit 0; }
else
    confirm "开始更新？" || { echo "  已取消"; exit 0; }
fi

# ---------------------------------------------------------------- 执行
sect "3/5  更新"

# redeploy 不动代码，写回滚点只会把上一次真正更新留下的回滚点覆盖成当前版本，
# 等于把回滚能力删掉。所以这一档不碰它。
if [ "$NO_PULL" = "1" ]; then
    note "未改动代码，保留原有回滚点"
else
    echo "$BEFORE" > "$ROLLBACK_FILE"
    ok "回滚点已记录（$(git rev-parse --short "$BEFORE")）"
fi

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
if [ "$NO_PULL" = "1" ]; then
    ok "代码保持在 $(git rev-parse --short HEAD)（本次不拉取）"
elif ! git pull --ff-only --quiet origin "$BRANCH"; then
    die "git pull 失败" "如果提示 divergent branches，说明本地有远端没有的提交，需要人工处理。"
else
    ok "代码已更新到 $(git rev-parse --short HEAD)（$(git diff --name-only "$BEFORE"..HEAD | wc -l | tr -d ' ') 个文件）"
fi

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
