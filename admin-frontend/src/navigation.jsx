import React from 'react';
import {
  DashboardOutlined,
  ShoppingOutlined,
  TransactionOutlined,
  FileTextOutlined,
  GiftOutlined,
  ReadOutlined,
  ToolOutlined,
  SettingOutlined,
  StopOutlined,
  AuditOutlined,
  KeyOutlined,
  ScheduleOutlined,
  RollbackOutlined,
} from '@ant-design/icons';

/**
 * Dynamic route chunk registry shared between React Router and sidebar hover prefetching.
 */
const loaders = {
  '/': () => import('./pages/Dashboard'),
  '/products': () => import('./pages/Products'),
  '/products/cards': () => import('./pages/ProductCards'),
  '/orders': () => import('./pages/Orders'),
  '/orders/detail': () => import('./pages/OrderDetail'),
  '/articles': () => import('./pages/Articles'),
  '/coupons': () => import('./pages/Coupons'),
  '/blacklists': () => import('./pages/Blacklists'),
  '/logs': () => import('./pages/Logs'),
  '/settings': () => import('./pages/Settings'),
  '/api-tokens': () => import('./pages/ApiTokens'),
  '/account': () => import('./pages/Account'),
  '/tasks': () => import('./pages/TaskCenter'),
  '/refunds': () => import('./pages/Refunds'),
  '/operations': () => import('./pages/Operations'),
  '/login': () => import('./pages/LoginPage'),
};

/** Lazy component for a route key. */
export const lazyPage = (key) => React.lazy(loaders[key]);

/** Prefetch route chunk on menu hover or focus. */
export const preloadPage = (key) => {
  const load = loaders[key];
  if (load) load().catch(() => {});
};

/**
 * Consolidated sidebar navigation tree.
 * Product categories live inside /products?tab=categories and article categories live
 * inside /articles?tab=categories; personal account security lives in the header menu.
 */
export const menuTree = {
  route: {
    path: '/',
    routes: [
      { path: '/', name: '经营概览', icon: <DashboardOutlined /> },
      { path: '/products', name: '商品与库存', icon: <ShoppingOutlined /> },
      {
        path: '/trade',
        name: '交易与售后',
        icon: <TransactionOutlined />,
        routes: [
          { path: '/orders', name: '订单管理', icon: <FileTextOutlined /> },
          { path: '/refunds', name: '退款管理', icon: <RollbackOutlined /> },
          { path: '/coupons', name: '优惠券', icon: <GiftOutlined /> },
        ],
      },
      { path: '/articles', name: '内容与公告', icon: <ReadOutlined /> },
      {
        path: '/system',
        name: '系统与安全',
        icon: <ToolOutlined />,
        routes: [
          { path: '/tasks', name: '任务中心', icon: <ScheduleOutlined /> },
          { path: '/operations', name: '运维监控', icon: <ToolOutlined /> },
          { path: '/settings', name: '系统设置', icon: <SettingOutlined /> },
          { path: '/blacklists', name: '风控黑名单', icon: <StopOutlined /> },
          { path: '/api-tokens', name: 'API 令牌', icon: <KeyOutlined /> },
          { path: '/logs', name: '审计日志', icon: <AuditOutlined /> },
        ],
      },
    ],
  },
};

/** Every sidebar leaf path, longest first. */
export const leafPaths = (() => {
  const out = [];
  const walk = (nodes) => nodes.forEach((n) => (n.routes ? walk(n.routes) : out.push(n.path)));
  walk(menuTree.route.routes);
  return out.filter((p) => p !== '/').sort((a, b) => b.length - a.length);
})();

/** Formal operational page metadata. */
export const pageMeta = {
  '/': { title: '经营概览', desc: '实时汇总交易指标、待处理业务事项与商品库存预警' },
  '/products': { title: '商品与库存', desc: '统一管理在售商品、商品分类与全站卡密一键批量运维' },
  '/products/cards': { title: '卡密库存管理', desc: '管理当前商品的卡密导入、库存状态切换与未售库存清理' },
  '/orders': { title: '订单管理', desc: '检索全站交易订单、核对异常收款并导出订单明细' },
  '/orders/detail': { title: '订单详情', desc: '核验网关交易流水、付款回执、交付卡密及售后换卡与退款记录' },
  '/refunds': { title: '退款管理', desc: '审核退款申请并登记原支付渠道的实际退款流水凭证' },
  '/coupons': { title: '优惠券', desc: '配置折扣规则、适用商品范围、使用额度与生效周期' },
  '/articles': { title: '内容与公告', desc: '发布与维护帮助文档、运营公告、内嵌商品卡片及文章分类' },
  '/tasks': { title: '任务中心', desc: '监控邮件与消息通知投递、付款自动对账及搜索引擎推送队列' },
  '/operations': { title: '运维监控', desc: '检查后台服务心跳、清理未引用素材并管理全量数据备份' },
  '/settings': { title: '系统设置', desc: '配置站点品牌、SEO 收录、支付网关、通知渠道、风控与自动备份策略' },
  '/blacklists': { title: '风控黑名单', desc: '管理异常 IP 与邮箱封禁规则，拦截高风险下单请求' },
  '/api-tokens': { title: 'API 令牌', desc: '签发第三方接口访问凭据，配置权限范围、速率配额与来源白名单' },
  '/logs': { title: '审计日志', desc: '查阅后台管理操作流水与安全审计记录' },
  '/account': { title: '账户与安全', desc: '查看当前管理员登录信息，更新登录密码并管理双重身份验证' },
};

/** Resolve active sidebar key and header metadata for list, detail, and account routes. */
export function resolveRouteState(pathname) {
  const path = (pathname || '/').replace(/\/+$/, '') || '/';
  if (/^\/products\/[^/]+\/cards$/.test(path)) {
    return { selectedKey: '/products', meta: pageMeta['/products/cards'] };
  }
  if (/^\/orders\/[^/]+$/.test(path)) {
    return { selectedKey: '/orders', meta: pageMeta['/orders/detail'] };
  }
  if (path === '/account') {
    return { selectedKey: '', meta: pageMeta['/account'] };
  }
  const matchedLeaf = leafPaths.find((p) => path === p || path.startsWith(`${p}/`));
  if (matchedLeaf) {
    return { selectedKey: matchedLeaf, meta: pageMeta[matchedLeaf] || {} };
  }
  return { selectedKey: '/', meta: pageMeta['/'] };
}
