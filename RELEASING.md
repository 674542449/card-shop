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
4. 创建附注标签、推送该标签，然后发布 GitHub Release。不得通过重新打标签替换旧版本。
5. 核对远端只保留 `main`、标签指向预期提交、Release 已正式发布并标为 Latest。

例如，已有 `v1.0.4` 时，下一次默认发布 `v1.0.5`。在 Git、GitHub CLI 已安装并登录，且新提交已推送的情况下：

```bash
git fetch origin --prune --tags
git tag --list 'v*' --sort=-version:refname
git status --short
git rev-parse HEAD origin/main

# 上述两项提交必须一致，工作区必须干净；再次确认 v1.0.5 尚未使用。
git tag -a v1.0.5 -m 'CardShop v1.0.5'
git push origin refs/tags/v1.0.5
gh release create v1.0.5 --verify-tag --fail-on-no-commits --latest \
  --title 'CardShop v1.0.5' --notes-file .local/release-v1.0.5.md
```

`.local/release-v1.0.5.md` 是预先准备的发布说明，不进入代码仓库。如果标签已推送但 Release 创建失败，先核对远端状态，再用同一个标签重试创建 Release。

本文为维护者发版流程，店铺首次安装见 [DEPLOY.md](DEPLOY.md)。版本发布本身不会自动部署到服务器；历史版本变更记录保留在对应 GitHub Release 中。
