import React from 'react';
import {
  DashboardOutlined,
  AppstoreOutlined,
  ShoppingOutlined,
  FolderOutlined,
  TransactionOutlined,
  FileTextOutlined,
  GiftOutlined,
  ReadOutlined,
  ProfileOutlined,
  TagsOutlined,
  ToolOutlined,
  SettingOutlined,
  StopOutlined,
  HistoryOutlined,
  KeyOutlined,
  UserOutlined,
} from '@ant-design/icons';

/**
 * One list, two consumers.
 *
 * Every page's dynamic import lives here beside its route, so the router can render
 * it lazily and the sidebar can warm the chunk on hover from the same entry. Kept as
 * two separate lists, a new page could be added to the router and forgotten in the
 * menu — which is how the old flat menu drifted out of step with the routes.
 *
 * Detail routes are here too even though they never appear in the sidebar: they are
 * reached from a table row, and they need the same lazy treatment.
 */
const loaders = {
  '/': () => import('./pages/Dashboard'),
  '/categories': () => import('./pages/Categories'),
  '/products': () => import('./pages/Products'),
  '/products/cards': () => import('./pages/ProductCards'),
  '/orders': () => import('./pages/Orders'),
  '/orders/detail': () => import('./pages/OrderDetail'),
  '/articles': () => import('./pages/Articles'),
  '/article-categories': () => import('./pages/ArticleCategories'),
  '/coupons': () => import('./pages/Coupons'),
  '/blacklists': () => import('./pages/Blacklists'),
  '/logs': () => import('./pages/Logs'),
  '/settings': () => import('./pages/Settings'),
  '/api-tokens': () => import('./pages/ApiTokens'),
  '/account': () => import('./pages/Account'),
  '/tasks': () => import('./pages/TaskCenter'),
  '/refunds': () => import('./pages/Refunds'),
  '/admins': () => import('./pages/AdminAccounts'),
  '/operations': () => import('./pages/Operations'),
  '/login': () => import('./pages/LoginPage'),
};

/** Lazy component for a route key. */
export const lazyPage = (key) => React.lazy(loaders[key]);

/**
 * Start fetching a page's chunk before it is needed.
 *
 * Called on hover and on keyboard focus of a menu item. By the time the click lands
 * the chunk is usually already in memory, so the content area swaps straight to the
 * page instead of showing a loading state at all. Failures are ignored on purpose —
 * this is a prefetch, and the real navigation will surface any genuine error.
 */
export const preloadPage = (key) => {
  const load = loaders[key];
  if (load) load().catch(() => {});
};

/**
 * The sidebar tree.
 *
 * Grouped rather than flat. Ten sibling entries made every screen look equally
 * important and gave the operator no map of the system; four groups say what this
 * console is actually for — stock, money, content, and the machinery underneath.
 * The grouping is also what makes the breadcrumb meaningful, since a one-level menu
 * has nothing to put in one.
 */
export const menuTree = {
  route: {
    path: '/',
    routes: [
      { path: '/', name: '概览', icon: <DashboardOutlined /> },
      {
        path: '/catalog',
        name: '商品',
        icon: <AppstoreOutlined />,
        routes: [
          { path: '/products', name: '商品管理', icon: <ShoppingOutlined /> },
          { path: '/categories', name: '商品分类', icon: <FolderOutlined /> },
        ],
      },
      {
        path: '/trade',
        name: '交易',
        icon: <TransactionOutlined />,
        routes: [
          { path: '/refunds', name: '退款管理', icon: <TransactionOutlined /> },
          { path: '/orders', name: '订单管理', icon: <FileTextOutlined /> },
          { path: '/coupons', name: '优惠券', icon: <GiftOutlined /> },
        ],
      },
      {
        path: '/content',
        name: '内容',
        icon: <ReadOutlined />,
        routes: [
          { path: '/articles', name: '文章管理', icon: <ProfileOutlined /> },
          { path: '/article-categories', name: '文章分类', icon: <TagsOutlined /> },
        ],
      },
      {
        path: '/system',
        name: '系统',
        icon: <ToolOutlined />,
        routes: [
          { path: '/admins', name: '管理员', icon: <UserOutlined /> },
          { path: '/operations', name: '运维', icon: <ToolOutlined /> },
          { path: '/settings', name: '系统设置', icon: <SettingOutlined /> },
          { path: '/api-tokens', name: 'API 令牌', icon: <KeyOutlined /> },
          { path: '/account', name: '账户与密码', icon: <UserOutlined /> },
          { path: '/blacklists', name: '黑名单', icon: <StopOutlined /> },
          { path: '/logs', name: '操作日志', icon: <HistoryOutlined /> },
          { path: '/tasks', name: '任务中心', icon: <HistoryOutlined /> },
        ],
      },
    ],
  },
};

/** Every leaf path, longest first — used to resolve which menu entry is current. */
export const leafPaths = (() => {
  const out = [];
  const walk = (nodes) => nodes.forEach((n) => (n.routes ? walk(n.routes) : out.push(n.path)));
  walk(menuTree.route.routes);
  return out.filter((p) => p !== '/').sort((a, b) => b.length - a.length);
})();

/**
 * Page header text, resolved by AdminLayout from the current route.
 *
 * Every page used to open straight into a bare Card with no title — part of why the
 * console read as unfinished. Kept here rather than repeated in twelve page files so
 * a page cannot end up with a heading that disagrees with its menu entry.
 *
 * The descriptions say what the operator can DO on the page, not what the page is
 * called; the title already says that.
 */
export const pageMeta = {
  '/refunds': {title:'退款管理',desc:'审核退款申请，登记实际退款凭证'},
  '/admins': {title:'管理员',desc:'管理账户启停与操作权限'},
  '/operations': {title:'运维',desc:'查看运行健康，管理备份与上传素材'},
  '/': { title: '概览', desc: '今天的成交、库存和待处理的事情' },
  '/products': { title: '商品管理', desc: '上架商品，设置价格、起购数量和库存' },
  '/categories': { title: '商品分类', desc: '给商品分组，决定它们在首页的排列顺序' },
  '/orders': { title: '订单管理', desc: '查看成交、确认付款、补发卡密' },
  '/coupons': { title: '优惠券', desc: '创建折扣码，限制使用次数和有效期' },
  '/articles': { title: '文章管理', desc: '发布公告、使用教程和帮助页面' },
  '/article-categories': { title: '文章分类', desc: '给文章分组' },
  '/settings': { title: '系统设置', desc: '站点信息、支付网关、邮件发送和安全设置' },
  '/api-tokens': { title: 'API 令牌', desc: '创建调用凭据，查看使用记录并随时停用或撤销' },
  '/account': { title: '账户与密码', desc: '查看登录记录，更新管理账户密码' },
  '/blacklists': { title: '黑名单', desc: '拉黑滥用的 IP 或邮箱，被拉黑的访客无法下单' },
  '/logs': { title: '操作日志', desc: '后台每一次改动的记录' },
  '/tasks': { title: '任务中心', desc: '查看通知、付款对账和搜索引擎推送，处理失败任务' },
};
