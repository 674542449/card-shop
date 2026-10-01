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
3. 根据已获取的远端标签再次核对版本号尚未使用，将功能、修复、兼容性变化、升级步骤和验证范围写入发布说明。
4. 创建附注标签、推送该标签，然后发布 GitHub Release。不得通过重新打标签替换旧版本。
5. 核对远端只保留 `main`、标签指向预期提交、Release 已正式发布并标为 Latest。

例如，已有 `v1.0.0` 时，下一次默认发布 `v1.0.1`。在 Git、GitHub CLI 已安装并登录，且新提交已推送的情况下：

```bash
git fetch origin --prune --tags
git tag --list 'v*' --sort=-version:refname
git status --short
git rev-parse HEAD origin/main

# 上述两项提交必须一致，工作区必须干净；再次确认 v1.0.1 尚未使用。
git tag -a v1.0.1 -m 'CardShop v1.0.1'
git push origin refs/tags/v1.0.1
gh release create v1.0.1 --verify-tag --fail-on-no-commits --latest \
  --title 'CardShop v1.0.1' --notes-file .local/release-v1.0.1.md
```

`.local/release-v1.0.1.md` 是预先准备的发布说明，不进入代码仓库。如果标签已推送但 Release 创建失败，先核对远端状态，再用同一个标签重试创建 Release。

应用升级仍按 [DEPLOY.md](DEPLOY.md) 进行；版本发布本身不会自动部署到服务器。

## 旧部署从 master 迁移

远端 `master` 已移除，旧服务器需切换到 `main`。先备份数据库并保存本地改动；以下操作要求已跟踪文件的工作区干净。

当前分支是 `master`，且本地尚无 `main` 时，重命名分支可以保留当前提交，让更新脚本正常比较并执行升级：

```bash
git status --short
git fetch origin --prune
git branch -m main
git branch --set-upstream-to=origin/main main
./scripts/update.sh --build
```

如果本地已经有 `main`，切换后先确认它包含原 `master` 的本地提交，再执行更新：

```bash
git fetch origin --prune
git switch main
git merge --ff-only master
git branch --set-upstream-to=origin/main main
./scripts/update.sh --build
git branch -d master
```

若 `--ff-only` 无法合并，先处理分叉的本地提交；不要强制删除分支或覆盖改动。确认更新成功后才删除本地 `master`。

迁移时保留 `--build`：即使切换后的提交已经与远端一致，也要执行重建与重启，让数据库迁移、缓存、调度器和通知进程加载新版本。非 Docker 部署请使用自己的部署流程完成同样的迁移和进程重载，Windows 本地开发通过 `start-dev.ps1` 启动环境。
