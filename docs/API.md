# API 接口

API 使用 Bearer Token 鉴权。在后台「API 令牌」中创建、停用和配置额度。
默认每令牌每分钟 120 次请求、20 次下单，同时最多占用 3 笔待付订单、100 张卡密。
额度按令牌统计，换 IP 不会重置；后台可调整。下单、查单、取消还分别受每 IP 20、30、10 次/分钟限制。首次凭据验证与错误猜测另共享网页/API 的 15 分钟额度：同邮箱与 IP 5 次、同邮箱 50 次、同 IP 20 次。成功不会清空猜密计数。

API 订单成功创建并发起支付，或首次通过密码验证后，服务器保存 10 分钟的验证证明，绑定创建令牌、订单、邮箱、密码 HMAC 和当前密码哈希，不缓存明文密码。正常批量下单查状态和重复轮询仍受上述请求额度限制，不继续消耗猜密次数；错密码、换令牌和密码变更不能复用证明。创建失败不产生证明，也不清空猜密次数。

下单客户端应为每次购买生成唯一 `Idempotency-Key` 请求头（1–128 位字母、数字或 `. _ : -`），网络失败重试时原样复用键和参数。同一令牌、同键、同参数返回持久保存的原响应，不再次占库存或优惠次数；响应头 `Idempotency-Replayed: true` 表示重放。同键参数不同返回 409，不同令牌独立。未提供键仍按每次请求创建订单。支付发起失败也会保存原失败响应；需要重新购买时请使用新键。重放的支付链接可能已过期，订单当前状态应通过查单确认。映射仅保存参数 HMAC，支付响应加密存储，不新增明文查询密码。

## 权限范围

| 范围 | 对应操作 |
| --- | --- |
| `products:read` | 商品列表与详情 |
| `orders:create` | 创建订单 |
| `orders:query` | 查询本令牌创建的订单 |
| `orders:cancel` | 取消本令牌创建的未付款订单 |

后台新建令牌默认勾选前三项，需要取消功能时额外勾选 `orders:cancel`。
`scopes=[]` 表示无接口权限；为每个令牌明确配置所需范围。

## 接口与下单

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/api/v1/products` | 商品列表 |
| GET | `/api/v1/products/{id}` | 商品详情 |
| POST | `/api/v1/orders` | 创建订单 |
| POST | `/api/v1/orders/{order_no}/query` | 查询订单，正文传递邮箱与查询密码 |
| POST | `/api/v1/orders/{order_no}/cancel` | 取消本令牌创建的未付款订单，正文传递邮箱与查询密码 |

请求示例：

```bash
curl -H "Authorization: Bearer YOUR_TOKEN" \
     https://你的域名/api/v1/products
```

商品列表接受 `page`（从 1 开始）和 `per_page`（默认 15，范围 1–100）。响应包含 `data` 商品数组及 `current_page`、`last_page`、`per_page`、`total` 等分页字段；商品提供 ID、名称、价格、购买数量上下限、分类和可售库存。单件详情在 `data` 中另提供 `wholesale_prices` 阶梯价格。

创建订单正文：

| 字段 | 要求 |
| --- | --- |
| `product_id` | 商品 ID；商品及分类须允许购买 |
| `quantity` | 正整数，须满足商品购买数量上下限、库存和令牌额度 |
| `email` | 合法邮箱，最多 200 个字符 |
| `query_password` | 6–50 个字符，且不超过 72 字节 |
| `payment_method` | `alipay`、`wechat`、`usdt_trc20`；BEpusdt 另支持 `usdt_bep20`、`usdt_polygon` |
| `coupon_code` | 可选优惠码，最多 50 个字符；服务端校验有效期、名额和商品范围 |

原版 EPUSDT 的 `usdt_trc20` 是兼容入口，实际使用网关默认网络；须在后台配置对应的支付网关。
价格和折扣由服务器计算，客户端提交的金额或付款状态不作为计价、付款或交付依据。

```bash
curl -X POST -H "Authorization: Bearer YOUR_TOKEN" \
     -H "Content-Type: application/json" -H "Accept: application/json" \
     -H "Idempotency-Key: YOUR_UNIQUE_PURCHASE_ID" \
     --data '{"product_id":1,"quantity":1,"email":"buyer@example.test","query_password":"YOUR_QUERY_PASSWORD","payment_method":"alipay"}' \
     https://你的域名/api/v1/orders
```

成功返回 HTTP 201，`data` 包含 `order_no`、`total_amount`、`discount_amount`、`payment_method`、
`expires_at`、`payment_url` 和 `trade_id`。`payment_url` 为支付链接，`trade_id` 随网关返回，可能为 `null` 或空字符串。201 表示订单创建及支付发起成功，
卡密交付以服务端确认有效付款后的查单结果为准。

查单示例（仅示例凭据）：

```bash
curl -X POST -H "Authorization: Bearer YOUR_TOKEN" \
     -H "Content-Type: application/json" \
     --data '{"email":"buyer@example.test","query_password":"YOUR_QUERY_PASSWORD"}' \
     https://你的域名/api/v1/orders/YOUR_ORDER_NO/query
```

查单仅支持 POST 正文凭据，响应禁止缓存。API 查单仅允许创建该订单的令牌，网页订单使用前台邮箱与密码查单。

查单成功返回 HTTP 200，`data` 提供订单、商品名称、数量、单价、实付、折扣、支付方式和时间信息：

| 字段 | 含义 |
| --- | --- |
| `status` | `pending` 待付款、`paid` 已付款、`expired` 已过期、`closed` 已关闭 |
| `cards` | 仅 `paid` 时提供卡密数组；售后换卡后为当前交付卡密 |
| `payment_review` | 是否存在待人工核对的付款，不应据此再次付款 |
| `payment_received_amount` / `payment_received_at` / `payment_received_currency` | 已记录人民币收款信息，不代替订单交付状态 |
| `refund_enabled` | 店主是否开放新退款申请 |
| `refund_balance` | 原付款的 `maximum` 总额度、`reserved` 申请预占、`completed` 已退、`available` 剩余额度 |
| `refunds` | 买家可见退款摘要，包含公开处理说明及完成时间，不含内部备注或转账凭证 |
| `paid_at` / `expires_at` / `created_at` | 付款、订单到期和创建时间 |

只有创建订单的 HTTP 201 或支付链接跳转成功，均不能作为交付依据；通过查单确认 `status` 与 `cards`。金额按服务端精度处理，避免客户端浮点运算改变含义。

创建与查询应使用同一个令牌，并配置所需范围。删除令牌会解除订单关联，新建令牌不能接管这些订单；买家仍可通过前台邮箱与查询密码查单，或联系店主处理。
Nginx 访问日志不记录查询参数、Referer、请求正文或 Authorization。

取消接口需要 `orders:cancel` 范围。取消凭据使用与查单相同的 JSON 正文，URL 查询参数不接受密码。只有创建订单的令牌可以取消该订单，已付款或已有付款回执的订单会被拒绝。取消与支付回调使用订单行锁，释放库存和优惠次数仅执行一次；若网关已经收款但有效回调稍后才到，仍按实际付款处理并在需要时进入人工核对。

## 错误处理

读取 HTTP 状态码和 JSON 的 `message` / `errors` 字段。令牌无效或到期返回 401，无权限或不满足 IP 范围返回 403，参数或业务校验失败返回 422，额度触发返回 429；幂等键与参数冲突返回 409。不要针对永久参数错误无条件重复下单。网络不确定时先使用同键、同参数重试并查单；一次新的购买应生成新键。

[返回项目介绍](../README.md) · [首次安装](../DEPLOY.md)
