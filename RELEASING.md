# 分支与版本发布

项目本地和 GitHub 只保留 `main` 分支。发版使用 Git 标签与 GitHub Release，不创建版本分支。

## 版本号

- 正式版本采用 `v主版本.次版本.补丁版本`，首个版本为 `v1.0.0`。
- 每次发布先获取远端标签，按已有最高正式版本默认递增补丁号：`v1.0.0` → `v1.0.1` → `v1.0.2`。
- 用户明确要求次版本或主版本升级时，相应递增并将后面的数字归零。
- 每个版本使用新的附注标签，指向已推送的 `main` 提交；已发布标签不覆盖、不移动、不复用。
- 发布后修复问题，需要新提交和下一个版本号。

## 发布步骤

1. 完成改动、回归与对应文档，确认待发布版本号并同步 README 的版本入口、锁定依赖版本、功能/API 描述和实际验证记录。若修改后台源码，重新构建并提交 `public/admin-assets/` 和构建标记；检查不包含环境密钥、数据库、日志或本地测试数据。
2. 获取 `origin/main` 和全部远端标签，确认工作区干净、当前分支为 `main`，并确认要发布的提交已推送。
3. 根据已获取的远端标签再次核对版本号尚未使用，将本次变化、配置要求和实际验证范围写入发布说明。README 和 DEPLOY 始终面向当前正式版的首次安装，不混入历史版本差异。
4. 创建附注标签、推送该标签，先创建草稿 Release。等待 AMD64 / ARM64 的测试、镜像构建与生产验收以及多架构索引发布全部成功，核对应用和 Nginx 均含两个平台且摘要与通过验收的镜像一致，再公开 Release 并设为 Latest。不得通过重新打标签替换旧版本。
5. 核对远端只保留 `main`、标签指向预期提交、Release 已正式发布并标为 Latest。

下列命令在 Bash 中运行，要求 Git、GitHub CLI 已安装并登录，且新提交已推送。每次都根据远端实际标签确定新版本，不直接复制历史版本号：

```bash
set -euo pipefail
git fetch origin --prune --tags
git tag --list 'v*' --sort=-version:refname
git status --short
git rev-parse HEAD origin/main
test "$(git branch --show-current)" = main
test -z "$(git status --porcelain)"
test "$(git rev-parse HEAD)" = "$(git rev-parse origin/main)"

# 上述两项提交必须一致，工作区必须干净；确认新版本尚未使用。
# 例如远端最高版本为 v1.0.8 时，下一补丁版为 v1.0.9。
read -r -p '输入已核对的新版本号（v主.次.补丁）：' VERSION
[[ "$VERSION" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] || exit 1
if git show-ref --verify --quiet "refs/tags/$VERSION"; then
  echo '版本标签已存在，停止发布。'
  exit 1
fi
test -s ".local/release-$VERSION.md" || exit 1
git tag -a "$VERSION" -m "CardShop $VERSION"
git push origin "refs/tags/$VERSION"
gh release create "$VERSION" --verify-tag --fail-on-no-commits --draft \
  --title "CardShop $VERSION" --notes-file ".local/release-$VERSION.md"
```

CI 完成后检查 `docker buildx imagetools inspect "ghcr.io/674542449/card-shop:$VERSION"` 与对应 `card-shop-nginx` 索引，再运行 `gh release edit "$VERSION" --draft=false --latest`。如只有某个平台完成，不要将其临时架构标签当成完整正式版本。推荐部署固定索引摘要，而非平台子镜像摘要。

`.local/release-$VERSION.md` 是预先准备的发布说明，不进入代码仓库。如果标签已推送但 Release 创建失败，先核对远端状态，再用同一个标签重试创建 Release。

发布完成后，把最终 Actions 链接、已验证的计数和多架构索引摘要补入 `tests/README.md`，同步 README、安装指南、架构、后台功能、API 与模板说明。仅补充文档时可作为新的 `main` 提交，明确对应的正式版本；不移动该版本标签，也不将文档提交写成重新执行过的业务测试。

本文为维护者发版流程，店铺首次安装见 [DEPLOY.md](DEPLOY.md)。版本发布本身不会自动部署到服务器；历史版本变更记录保留在对应 GitHub Release 中。
