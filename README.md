# CardShop - 个人发卡平台

基于 Laravel 12 构建的自动发卡平台，支持易支付（支付宝/微信）和 EPUSDT（TRC20/BEP20/Polygon）多链 USDT 收款，Docker Compose 一键部署。

## 功能概览

### 前台
- **商品展示** — 分类浏览、商品详情（Markdown 渲染）、阶梯批发价
- **自动发卡** — 支付成功后自动发送卡密到页面和邮箱
- **订单查询** — 凭邮箱 + 查询密码查看历史订单和卡密
- **文章栏目** — Markdown 文章系统，支持文章内嵌商品卡片引流
- **SEO 优化** — 独立 TDK、Sitemap 自动生成、JSON-LD 结构化数据、百度推送、Bing IndexNow

### 支付
- **易支付** — 支付宝、微信扫码支付
- **EPUSDT** — USDT 链上支付，支持 TRC20 / BEP20 / Polygon 三条链

### 后台管理
- 仪表盘（销售统计、趋势图、库存预警）
- 商品管理（分类、批量导入卡密、批发价设置）
- 订单管理（筛选、手动补发、掉单处理、CSV 导出）
- 文章管理（Markdown 编辑器、封面图、SEO 字段）
- 优惠码管理（固定/百分比折扣、有效期、使用限制）
- 黑名单管理（IP / 邮箱拉黑）
- 操作日志（全部关键操作审计记录）
- 系统设置（站点、支付、邮件、Telegram、SEO、安全）

### 通知与集成
- **Telegram Bot** — 新订单、库存预警实时推送
- **邮件通知** — SMTP 发送卡密，自定义邮件模板
- **RESTful API** — Token 鉴权，支持第三方对接

### 安全
- Cloudflare Turnstile 人机验证
- Redis 滑动窗口限流
- 支付回调 timing-safe 签名验证
- Redis 分布式锁防并发超卖
- CSRF 防护、bcrypt 密码哈希、登录失败锁定
- IP / 邮箱黑名单

## 技术栈

| 组件 | 版本 |
|------|------|
| PHP | 8.3 |
| Laravel | 12 |
| PostgreSQL | 17 |
| Redis | 7 |
| Nginx | stable |
| Docker Compose | 最新 |

## 环境要求

- Docker 和 Docker Compose
- 至少 1GB 内存
- 开放 80 端口

## 安装部署

### 1. 克隆项目

```bash
git clone https://github.com/674542449/card-shop.git
cd card-shop
```

### 2. 配置环境变量

```bash
cp .env.example .env
```

编辑 `.env` 文件，修改以下关键配置：

```env
# 应用
APP_URL=https://你的域名
APP_ENV=production
APP_DEBUG=false

# 数据库（与 docker-compose.yml 中一致）
DB_DATABASE=cardshop
DB_USERNAME=cardshop
DB_PASSWORD=修改为强密码

# Redis
REDIS_PASSWORD=null
```

同时修改 `docker-compose.yml` 中 PostgreSQL 的密码，确保与 `.env` 一致：

```yaml
environment:
  POSTGRES_DB: cardshop
  POSTGRES_USER: cardshop
  POSTGRES_PASSWORD: 修改为强密码
```

### 3. 启动容器

```bash
docker compose up -d --build
```

首次构建需要几分钟，会自动安装 PHP 依赖。

### 4. 初始化应用

```bash
# 生成应用密钥
docker compose exec app php artisan key:generate

# 运行数据库迁移和种子
docker compose exec app php artisan migrate --seed

# 创建存储目录软链接
docker compose exec app php artisan storage:link
```

### 5. 访问

- 前台：`http://你的IP`
- 后台：`http://你的IP/admin`
- 默认管理员：`admin` / `admin888`

> **首次登录后请立即修改管理员密码。**

## 部署后配置

登录后台「系统设置」完成以下配置：

### 支付设置
- **易支付**：填写 API 地址、商户 ID、商户密钥
- **EPUSDT**：填写 API 地址和 Token（需要单独部署 [EPUSDT](https://github.com/GMWalletApp/epusdt) 服务）

### 邮件设置
SMTP 参数在 `.env` 文件中配置，邮件模板在后台设置。

支持的模板变量：
- `{{site_name}}` — 站点名称
- `{{order_no}}` — 订单号
- `{{product_name}}` — 商品名称
- `{{quantity}}` — 数量
- `{{amount}}` — 金额
- `{{cards}}` — 卡密内容

### Telegram 通知
1. 通过 [@BotFather](https://t.me/BotFather) 创建 Bot，获取 Token
2. 获取 Chat ID（可通过 [@userinfobot](https://t.me/userinfobot) 查询）
3. 在后台填入 Bot Token 和 Chat ID，开启通知

### Turnstile 验证码
1. 在 [Cloudflare Dashboard](https://dash.cloudflare.com/turnstile) 创建 Turnstile 站点
2. 在后台填入 Site Key 和 Secret Key

### SEO 设置
- 填写默认 TDK（标题、描述、关键词）
- 填写百度推送 Token（可选）
- 填写 Bing IndexNow Key（可选）

## API 接口

API 使用 Bearer Token 鉴权。在后台「系统设置」中创建 API Token。

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/api/v1/products` | 商品列表 |
| GET | `/api/v1/products/{id}` | 商品详情 |
| POST | `/api/v1/orders` | 创建订单 |
| GET | `/api/v1/orders/{order_no}` | 查询订单 |

请求示例：

```bash
curl -H "Authorization: Bearer YOUR_TOKEN" \
     https://你的域名/api/v1/products
```

## 目录结构

```
card-shop/
├── app/
│   ├── Http/Controllers/
│   │   ├── Admin/          # 后台控制器（12个）
│   │   ├── Api/            # API 控制器
│   │   └── Front/          # 前台控制器（6个）
│   ├── Models/             # Eloquent 模型（13个）
│   ├── Services/           # 业务服务层（7个）
│   ├── Http/Middleware/     # 中间件（4个）
│   └── Http/Requests/      # 表单验证（7个）
├── config/                 # 应用配置
├── database/
│   ├── migrations/         # 数据库迁移（13个）
│   └── seeders/            # 种子数据
├── docker/                 # Docker 配置
├── public/                 # 静态资源
├── resources/views/        # Blade 模板
│   ├── admin/              # 后台视图（20个）
│   ├── front/              # 前台视图（10个）
│   └── layouts/            # 布局模板
└── routes/                 # 路由定义
```

## 常用命令

```bash
# 启动服务
docker compose up -d

# 停止服务
docker compose down

# 查看日志
docker compose logs -f app

# 进入 PHP 容器
docker compose exec app bash

# 清理缓存
docker compose exec app php artisan cache:clear
docker compose exec app php artisan config:clear
docker compose exec app php artisan view:clear

# 数据库备份
docker compose exec postgres pg_dump -U cardshop cardshop > backup.sql

# 数据库恢复
docker compose exec -T postgres psql -U cardshop cardshop < backup.sql
```

## 更新升级

```bash
git pull origin master
docker compose up -d --build
docker compose exec app php artisan migrate
docker compose exec app php artisan cache:clear
```

## License

MIT
