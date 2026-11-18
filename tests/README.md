# 自动化测试与回归验收

适用正式版本：**v1.0.0**，核对日期：**2026-11-18**。

## 验证覆盖范围

每次提交与正式标签发布均在 GitHub Actions 原生 **AMD64 (`ubuntu-24.04`)** 与 **ARM64 (`ubuntu-24.04-arm`)** 双平台独立执行全量验证，任一平台未通过均阻断镜像发布：

| 验证维度 | 覆盖规模与内容 |
| --- | --- |
| PHP 特性与安全回归（PHPUnit） | **541 个测试 / 5,617 项断言**（含真实 PostgreSQL 备份与单事务恢复集成测试） |
| 管理后台前端回归（Node + JSDOM） | **34 个测试**（覆盖单管理员导航、商品批量改介绍、异步防串单、设置保存队列、卡密与任务操作） |
| 商城前台与多架构索引脚本（Node） | **12 个测试**（覆盖支付状态轮询恢复、双架构镜像索引摘要与平台校验） |
| 容器启动与构建脚本（Bash） | **7 类 Composer 启动指纹场景** + 全部 Shell 脚本语法检查 + 静态资源构建标记一致性校验 |
| 依赖安全审计 | `composer audit --locked` 零漏洞；`npm audit` 生产依赖零高危/严重漏洞、全量依赖零严重漏洞 |
| 生产容器首次安装与重启验收 | 多阶段构建 `app` 与 `nginx` 镜像，拉起全部 **9 个生产服务**（`ci-production-smoke.sh`），核验 64 位运行时、数据库迁移、外部 keyring、HTTP 响应头、工作进程心跳及容器重启不变量 |

### 核心安全与业务测试矩阵

- **统一下单与防刷风控**（`AntiAbuseHardeningTest`、`CheckoutAndPaymentHardeningTest`、`BuyerAdversarialFlowTest`）：验证网页会话绑定签名 `checkout_key`、快捷抽屉与 bfcache `/order/intent` 自动刷新、`Sec-Fetch-Site: cross-site` 拦截、隐藏蜜罐字段、单 IP（含 IPv6 `/64` 聚合）与单邮箱待付单上限、单商品 80% 未付锁库熔断、Turnstile 二次验证，以及 API `Idempotency-Key` 并发争用保护。
- **搜索引擎收录与页面体验**（`SearchEngineCrawlTest`、`ThemeViewsTest`）：验证三套模板 TDK、Canonical 规范化分页、Google 图片 Sitemap、Schema.org JSON-LD（`Product` / `AggregateOffer` / `ItemList` / `Article` / `BreadcrumbList`）、FCrDNS 搜索引擎爬虫扫描陷阱豁免、独立 `404` `noindex` 页及敏感页 `X-Robots-Tag`。
- **支付验签、发货与退款边界**（`CardTheftPaymentSecurityTest`、`CardSecretAccessSecurityTest`）：验证易支付与两种 USDT 网关（传统 EPUSDT MD5、BEpusdt）签名核验、最低实付 `¥0.01`、不足额/错币种拦截、迟到与重复收款对账、退款预占防重发卡、售后换卡退役隔离及 30 分钟查单会话绑定。
- **外部密钥加密与完整备份恢复**（`RuntimeArchitectureTest`、`AdminSecretBoundarySecurityTest`）：验证 AES-256-GCM 外部 keyring 加解密、HMAC 卡密查重、Redis 敏感配置密文缓存、Phar 完整备份校验，以及 PostgreSQL `pg_dump` / `pg_restore` 排他锁与失败回滚。
- **纵深安全边界**（`DefenseInDepthSecurityTest`、`SensitiveResponseSecurityTest`）：验证商品/分类/文章图片写入协议白名单、全站 `ILIKE` 通配符字面转义、HTTPS `HSTS` 与 `Permissions-Policy` 响应头，以及出站支付网关防云元数据/内网 SSRF 与 DNS Rebinding 锁定。

## 本地复现命令

在已配置 PHP 8.3、Node.js 24、PostgreSQL 17、Redis 7 及隔离测试环境（`cardshop_testing` 数据库、Redis 10/11 与测试 keyring）的开发机执行：

```bash
php scripts/validate-admin-assets.php
php vendor/bin/phpunit
npm test --prefix admin-frontend
node --test tests/js/*.test.cjs
bash tests/shell/composer-install.test.sh
composer audit --locked
npm audit --prefix admin-frontend --omit=dev --audit-level=high
```

启用真实 PostgreSQL 恢复集成测试时设置环境变量 `SHOP_RUN_RESTORE_INTEGRATION=1`（需本地安装 PostgreSQL 17 客户端工具 `pg_dump` / `psql` 并具备测试库建库权限）。完整容器化验收流程见 [.github/workflows/ci.yml](../.github/workflows/ci.yml) 与 [scripts/ci-production-smoke.sh](../scripts/ci-production-smoke.sh)。

> 自动化回归仅代表已覆盖场景的验证结果；正式上线前仍须在目标服务器完成真实 HTTPS 域名、Cloudflare 代理、支付网关小额实付、SMTP 邮件及 Telegram 投递验收，详见 [DEPLOY.md](../DEPLOY.md)。
