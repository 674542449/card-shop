#!/usr/bin/env bash
#
# CardShop 一键迁移与全量恢复脚本（换新服务器或同机回滚均可一行命令完成）
#
# 用法：
#   sudo ./scripts/restore.sh /root/shop-xxx.tar.gz --keyring /root/shop-keyring.json
#   sudo ./scripts/restore.sh /root/shop-xxx.tar.gz                      # 同机已有 keyring 时可省略 --keyring
#   sudo ./scripts/restore.sh /root/shop-xxx.tar.gz --keyring /root/shop-keyring.json --dry-run
#
# 选项：
#   --keyring <路径>       指定离线保存的 shop-keyring.json（换新机必填）
#   --env-file <路径>      外部运行环境文件路径（默认 /etc/cardshop/runtime.env）
#   --secrets-dir <路径>   密钥目录（默认读取 env 文件中的 SHOP_SECRETS_DIR 或 /etc/cardshop/secrets）
#   --compose-file <路径>  Compose 文件路径（默认 docker-compose.production.yml）
#   --overwrite-env        用备份包内的 config.env 覆盖已有 env 文件（默认仅缺失时自动解出）
#   --dry-run, --check     仅核对输入文件并校验归档与密钥，不覆盖正式库
#   --yes, -y              免交互确认，直接执行
#   -h, --help             显示帮助
#
set -uo pipefail

ARCHIVE=""
KEYRING_SRC=""
ENV_FILE="/etc/cardshop/runtime.env"
SECRETS_DIR=""
COMPOSE_FILE="docker-compose.production.yml"
OVERWRITE_ENV=0
DRY_RUN=0
ASSUME_YES=0
DOCKER_BIN="${CARDSHOP_DOCKER_BIN:-docker}"

show_help() {
    sed -n '3,19p' "$0" | sed 's/^# \{0,1\}//'
}

while [ $# -gt 0 ]; do
    case "$1" in
        --keyring)
            [ $# -ge 2 ] || { echo "错误：--keyring 缺少文件路径" >&2; exit 2; }
            KEYRING_SRC="$2"; shift 2 ;;
        --env-file)
            [ $# -ge 2 ] || { echo "错误：--env-file 缺少文件路径" >&2; exit 2; }
            ENV_FILE="$2"; shift 2 ;;
        --secrets-dir)
            [ $# -ge 2 ] || { echo "错误：--secrets-dir 缺少目录路径" >&2; exit 2; }
            SECRETS_DIR="$2"; shift 2 ;;
        --compose-file)
            [ $# -ge 2 ] || { echo "错误：--compose-file 缺少文件路径" >&2; exit 2; }
            COMPOSE_FILE="$2"; shift 2 ;;
        --overwrite-env)
            OVERWRITE_ENV=1; shift ;;
        --dry-run|--check)
            DRY_RUN=1; shift ;;
        --yes|-y)
            ASSUME_YES=1; shift ;;
        -h|--help)
            show_help; exit 0 ;;
        -*)
            echo "错误：未知参数 $1（运行 $0 --help 查看用法）" >&2; exit 2 ;;
        *)
            if [ -n "$ARCHIVE" ]; then
                echo "错误：只支持指定一个备份归档文件（多余参数：$1）" >&2; exit 2
            fi
            ARCHIVE="$1"; shift ;;
    esac
done

RED=$'\033[31m'; GRN=$'\033[32m'; YEL=$'\033[33m'; BLD=$'\033[1m'; DIM=$'\033[2m'; RST=$'\033[0m'
step() { echo ""; echo "${BLD}==> $1${RST}"; }
ok()   { echo "  ${GRN}[OK]${RST} $1"; }
warn() { echo "  ${YEL}[!]${RST} $1"; }
die()  {
    echo "" >&2
    echo "${RED}恢复中止：$1${RST}" >&2
    [ -n "${2:-}" ] && echo "${DIM}$2${RST}" >&2
    exit 2
}

[ -n "$ARCHIVE" ] || die "请指定待恢复的 .tar.gz 备份文件路径。" "示例：sudo ./scripts/restore.sh /root/shop-xxx.tar.gz --keyring /root/shop-keyring.json"
[ -f "$ARCHIVE" ] && [ -r "$ARCHIVE" ] || die "备份文件不存在或不可读：$ARCHIVE"

BASENAME="$(basename "$ARCHIVE")"
case "$BASENAME" in
    *.tar.gz) ;;
    *) die "备份文件必须以 .tar.gz 结尾：$BASENAME" ;;
esac
if ! [[ "$BASENAME" =~ ^shop-[A-Za-z0-9-]+\.tar\.gz$ ]]; then
    TARGET_BASENAME="shop-restore-$(date +%Y%m%d-%H%M%S).tar.gz"
else
    TARGET_BASENAME="$BASENAME"
fi

SCRIPT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$SCRIPT_DIR" || die "无法进入项目根目录：$SCRIPT_DIR"
[ -f "$COMPOSE_FILE" ] || die "找不到 Compose 文件：$COMPOSE_FILE"

command -v tar >/dev/null 2>&1 || die "缺少 tar 命令"
command -v "$DOCKER_BIN" >/dev/null 2>&1 || die "找不到 Docker 命令：$DOCKER_BIN"
"$DOCKER_BIN" compose version >/dev/null 2>&1 || die "需要支持 'docker compose' 的 Compose CLI 插件"

read_env_value() {
    local file="$1" key="$2"
    [ -r "$file" ] || return 0
    grep -E "^${key}=" "$file" 2>/dev/null \
        | head -1 \
        | cut -d= -f2- \
        | tr -d '\r' \
        | sed -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'\$/\1/"
}

step "1/6 核对归档结构、独立密钥与环境配置"
if ! tar -tzf "$ARCHIVE" manifest.json database.dump >/dev/null 2>&1; then
    die "备份文件结构不完整（缺少 manifest.json 或 database.dump）：$ARCHIVE"
fi
ok "备份归档结构完整：$BASENAME"

if [ -n "$KEYRING_SRC" ]; then
    [ -f "$KEYRING_SRC" ] && [ -r "$KEYRING_SRC" ] || die "指定的密钥文件不存在或不可读：$KEYRING_SRC"
    if ! (grep -q '"versions"' "$KEYRING_SRC" 2>/dev/null && grep -q '"fingerprint"' "$KEYRING_SRC" 2>/dev/null && grep -q '"active"' "$KEYRING_SRC" 2>/dev/null); then
        die "密钥文件内容格式无效（缺少 active/versions/fingerprint 字段）：$KEYRING_SRC"
    fi
fi

ENV_DIR="$(dirname "$ENV_FILE")"
if [ -z "$SECRETS_DIR" ]; then
    SECRETS_DIR="$(read_env_value "$ENV_FILE" SHOP_SECRETS_DIR)"
    [ -n "$SECRETS_DIR" ] || SECRETS_DIR="/etc/cardshop/secrets"
fi
KEYRING_DEST="${SECRETS_DIR%/}/shop-keyring.json"

if [ -z "$KEYRING_SRC" ] && [ ! -f "$KEYRING_DEST" ]; then
    die "未找到独立密钥文件 $KEYRING_DEST。" "换新服务器请通过 --keyring /path/to/shop-keyring.json 指定旧机离线保存的密钥文件（切勿重新运行 secrets:init）。"
fi

ENV_NEEDS_EXTRACT=0
if [ ! -f "$ENV_FILE" ] || [ "$OVERWRITE_ENV" = "1" ]; then
    if tar -tzf "$ARCHIVE" config.env >/dev/null 2>&1; then
        ENV_NEEDS_EXTRACT=1
    elif [ ! -f "$ENV_FILE" ]; then
        die "环境文件 $ENV_FILE 不存在且备份包内未包含 config.env。" "请先准备好 $ENV_FILE（参考 DEPLOY.md 第 2 步）后重试。"
    fi
fi

if [ "$DRY_RUN" = "1" ]; then
    echo ""
    echo "  演练模式（--dry-run）检查计划："
    echo "    备份归档：$ARCHIVE"
    echo "    密钥目标：$KEYRING_DEST（来源：${KEYRING_SRC:-沿用现有密钥}）"
    echo "    环境文件：$ENV_FILE（$([ "$ENV_NEEDS_EXTRACT" = "1" ] && echo '将从归档 config.env 提取' || echo '沿用现有文件')）"
    echo "    Compose ：$COMPOSE_FILE"
    ok "前置条件检查全部通过（--dry-run 未做任何修改）。"
    exit 0
fi

if [ "$ASSUME_YES" != "1" ] && { [ -t 0 ] || [ -e /dev/tty ]; }; then
    echo ""
    warn "即将使用 $BASENAME 覆盖正式数据库与上传文件（恢复前会自动先创建一份安全快照）。"
    printf "  确认开始恢复？[y/N] "
    ANS=""
    read -r ANS < /dev/tty 2>/dev/null || ANS=""
    case "$ANS" in
        y|Y|yes|YES) ;;
        *) echo "已取消恢复。"; exit 0 ;;
    esac
fi

install_private_dir() {
    local dir="$1"
    mkdir -p "$dir" || die "无法创建目录：$dir"
    if [ "$(id -u)" = "0" ]; then
        chown root:33 "$dir" 2>/dev/null || true
    fi
    chmod 750 "$dir" 2>/dev/null || true
}

install_private_file() {
    local src="$1" dst="$2"
    cp "$src" "$dst" || die "无法写入文件：$dst"
    if [ "$(id -u)" = "0" ]; then
        chown root:33 "$dst" 2>/dev/null || true
    fi
    chmod 640 "$dst" 2>/dev/null || true
}

install_private_dir "$ENV_DIR"
install_private_dir "$SECRETS_DIR"

if [ -n "$KEYRING_SRC" ]; then
    if [ "$(cd "$(dirname "$KEYRING_SRC")" && pwd)/$(basename "$KEYRING_SRC")" != "$(cd "$(dirname "$KEYRING_DEST")" && pwd)/$(basename "$KEYRING_DEST")" ]; then
        install_private_file "$KEYRING_SRC" "$KEYRING_DEST"
    fi
    ok "独立密钥已就位：$KEYRING_DEST"
else
    ok "使用现有独立密钥：$KEYRING_DEST"
fi

if [ "$ENV_NEEDS_EXTRACT" = "1" ]; then
    TMP_ENV="$(mktemp)"
    if ! tar -xzOf "$ARCHIVE" config.env > "$TMP_ENV"; then
        rm -f "$TMP_ENV"
        die "从备份包提取 config.env 失败。"
    fi
    install_private_file "$TMP_ENV" "$ENV_FILE"
    rm -f "$TMP_ENV"
    ok "已从备份包自动恢复环境文件：$ENV_FILE"
else
    ok "保留现有环境文件：$ENV_FILE"
fi

DB_NAME="$(read_env_value "$ENV_FILE" DB_DATABASE)"
[ -n "$DB_NAME" ] || DB_NAME="cardshop"

export SHOP_ENV_FILE="$ENV_FILE"
export SHOP_SECRETS_DIR="$SECRETS_DIR"

shop() {
    "$DOCKER_BIN" compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" "$@"
}

step "2/6 拉取镜像、启动基础服务并暂停写入进程"
shop pull || warn "镜像拉取提示异常，尝试使用本机已有镜像继续..."
shop stop worker nginx >/dev/null 2>&1 || true
shop up -d --wait postgres redis || die "启动 postgres / redis 失败，请检查 $ENV_FILE 与 Docker 日志。"
shop up -d --no-deps app || die "启动 app 容器失败，请检查 $ENV_FILE 与 Docker 日志。"
ok "基础服务（postgres / redis）已就绪，app 已启动等待恢复，worker 与 nginx 已暂停"

step "3/6 进入维护模式并导入备份归档到容器存储卷"
shop exec -T --user www-data app php artisan down --retry=15 >/dev/null || die "进入维护模式（artisan down）失败。"

APP_CID="$(shop ps -q app | head -1 | tr -d '\r')"
[ -n "$APP_CID" ] || die "无法获取 app 容器 ID。"

shop exec -T --user www-data app sh -c 'mkdir -p storage/app/private/shop-backups && chmod 700 storage/app/private/shop-backups' || die "无法准备容器内备份目录。"
"$DOCKER_BIN" cp "$ARCHIVE" "${APP_CID}:/var/www/html/storage/app/private/shop-backups/${TARGET_BASENAME}" || die "拷贝备份文件进容器失败。"
shop exec -T --user root app sh -c "chown 33:33 'storage/app/private/shop-backups/${TARGET_BASENAME}' && chmod 600 'storage/app/private/shop-backups/${TARGET_BASENAME}'" || die "修正容器内备份文件权限失败。"
ok "备份归档已导入容器：storage/app/private/shop-backups/${TARGET_BASENAME}"

step "4/6 校验归档完整性与密钥指纹"
shop exec -T --user www-data app php artisan shop:restore "storage/app/private/shop-backups/${TARGET_BASENAME}" --verify-only >/dev/null \
    || die "备份完整性校验失败，站点已保持维护模式。" "请检查备份包是否损坏，或确认是否使用了旧机对应的 shop-keyring.json。"
ok "归档清单与全部文件 SHA-256 校验通过"

step "5/6 执行正式库单事务全量恢复（库名：${DB_NAME}）"
shop exec -T --user www-data app php artisan shop:restore "storage/app/private/shop-backups/${TARGET_BASENAME}" --database="${DB_NAME}" --confirm="restore:${DB_NAME}" \
    || die "正式库恢复失败，站点已保持维护模式以保护数据安全。" "请核对报错信息与 shop-keyring.json 后重试。"
ok "数据库、上传素材与私有归档已原子恢复"

step "6/6 清理缓存、解除维护模式并启动全部服务"
shop exec -T --user www-data app php artisan optimize:clear >/dev/null 2>&1 || true
shop exec -T --user www-data app php artisan up >/dev/null || die "解除维护模式（artisan up）失败。"
shop up -d --wait || die "拉起全部 5 个容器失败，请运行 docker compose logs 排查。"
ok "全部服务已恢复在线"

ADMIN_PATH_OUT="$(shop exec -T --user www-data app php artisan admin:path 2>/dev/null | tr -d '\r' || true)"
echo ""
echo "${GRN}${BLD}全量恢复完成！${RST}"
[ -n "$ADMIN_PATH_OUT" ] && echo "  后台入口信息：$ADMIN_PATH_OUT"
echo "  如为换新服务器，请确认 Cloudflare DNS 已指向新机 IP 且源站证书路径正确。"
