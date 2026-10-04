import React, { Suspense, useEffect, useState } from 'react';
import { Routes, Route, Navigate, useNavigate, useLocation } from 'react-router-dom';
import { ConfigProvider, Spin } from 'antd';
import zhCN from 'antd/locale/zh_CN';
import AdminLayout from './layouts/AdminLayout';
import { lazyPage } from './navigation';
import { getMe } from './services/api';

const LoginPage = lazyPage('/login');
const Dashboard = lazyPage('/');
const Categories = lazyPage('/categories');
const Products = lazyPage('/products');
const ProductCards = lazyPage('/products/cards');
const Orders = lazyPage('/orders');
const OrderDetail = lazyPage('/orders/detail');
const Articles = lazyPage('/articles');
const ArticleCategories = lazyPage('/article-categories');
const Coupons = lazyPage('/coupons');
const Blacklists = lazyPage('/blacklists');
const Logs = lazyPage('/logs');
const Settings = lazyPage('/settings');
const ApiTokens = lazyPage('/api-tokens');
const Account = lazyPage('/account');
const TaskCenter = lazyPage('/tasks');
function LegacyNotifications() {
  const { search } = useLocation();
  const params = new URLSearchParams(search);
  params.set('tab', 'notifications');
  return <Navigate to={'/tasks?' + params.toString()} replace />;
}
const Refunds = lazyPage('/refunds');
const AdminAccounts = lazyPage('/admins');
const Operations = lazyPage('/operations');

/** Warm paper, clay accents, and quiet contrast shared with the modern storefront. */
const theme = {
  token: {
    colorPrimary: '#ac5033',
    colorLink: '#ac5033',
    colorLinkHover: '#914128',
    colorSuccess: '#496447',
    colorWarning: '#9a7134',
    colorError: '#a34439',
    colorInfo: '#ac5033',
    colorText: '#302e28',
    colorTextSecondary: '#757167',
    colorBgLayout: '#faf9f5',
    colorBgContainer: '#fffefa',
    colorBgElevated: '#fffefa',
    colorBorder: '#d8d2c5',
    colorBorderSecondary: '#e7e3d9',
    colorFillAlter: '#f5f2eb',
    borderRadius: 8,
    borderRadiusLG: 12,
    controlHeight: 36,
    fontSize: 14,
    fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif',
    boxShadow: '0 8px 28px rgba(48, 46, 40, 0.08)',
    boxShadowSecondary: '0 12px 40px rgba(48, 46, 40, 0.1)',
  },
  components: {
    Table: {
      headerBg: '#f5f2eb',
      headerColor: '#757167',
      rowHoverBg: '#faf7f0',
      borderColor: '#e7e3d9',
      cellPaddingBlock: 12,
      headerBorderRadius: 8,
    },
    Card: { headerFontSize: 16, headerHeight: 56 },
    Button: { fontWeight: 500, primaryShadow: 'none', defaultShadow: 'none' },
    Input: { activeShadow: '0 0 0 2px rgba(172, 80, 51, 0.1)' },
    InputNumber: { activeShadow: '0 0 0 2px rgba(172, 80, 51, 0.1)' },
    Select: { optionSelectedBg: '#f3e7de', optionSelectedColor: '#914128' },
    Menu: {
      itemMarginInline: 10,
      itemBorderRadius: 7,
      itemSelectedBg: '#f0e8de',
      itemSelectedColor: '#914128',
      itemHoverBg: '#f1eee6',
      itemHeight: 38,
      subMenuItemBg: 'transparent',
    },
    Tabs: { inkBarColor: '#ac5033', itemSelectedColor: '#914128' },
    Tag: { defaultBg: '#f2efe7', defaultColor: '#757167' },
    Tooltip: { colorBgSpotlight: '#302e28' },
    Modal: { headerBg: '#fffefa', contentBg: '#fffefa', footerBg: '#fffefa' },
    Drawer: { colorBgElevated: '#fffefa' },
    Skeleton: { color: '#f2efe7', colorGradientEnd: '#e7e3d9' },
  },
};
const FullPageSpin = () => (
  <div style={{ display: 'flex', justifyContent: 'center', alignItems: 'center', minHeight: '100vh' }}>
    <Spin size="large" />
  </div>
);

/**
 * Resolves the session once and hands the result down.
 *
 * ProtectedRoute and AdminLayout each used to call getMe() on mount, so every load
 * asked the server who you were twice. One call now, and the answer is passed to the
 * layout instead of being fetched again.
 */
function RequireAuth({ children }) {
  const [state, setState] = useState({ checking: true, admin: null });
  const navigate = useNavigate();

  useEffect(() => {
    let alive = true;
    getMe()
      .then((res) => alive && setState({ checking: false, admin: res.data?.data || res.data }))
      .catch(() => {
        if (!alive) return;
        setState({ checking: false, admin: null });
        navigate('/login', { replace: true });
      });
    return () => {
      alive = false;
    };
  }, [navigate]);

  if (state.checking) return <FullPageSpin />;
  if (!state.admin) return null;

  return children(state.admin);
}

export default function App() {
  return (
    <ConfigProvider locale={zhCN} theme={theme}>
      <Routes>
        {/* Its own boundary: the login screen has no frame to preserve, so a
            full-page spinner is the right fallback there and only there. */}
        <Route
          path="/login"
          element={
            <Suspense fallback={<FullPageSpin />}>
              <LoginPage />
            </Suspense>
          }
        />
        {/*
          Must be "/", not a splat pattern. A splat segment has to be the last
          thing in a pattern, so nested children under a splat parent compile to a
          path with the splat in the middle, which never matches.

          Note there is NO Suspense here. The one around the child pages lives inside
          AdminLayout, wrapped around <Outlet /> — putting it at this level is what
          made every navigation blank the whole application.
        */}
        <Route path="/" element={<RequireAuth>{(admin) => <AdminLayout admin={admin} />}</RequireAuth>}>
          <Route index element={<Dashboard />} />
          <Route path="categories" element={<Categories />} />
          <Route path="products" element={<Products />} />
          <Route path="products/:productId/cards" element={<ProductCards />} />
          <Route path="orders" element={<Orders />} />
          <Route path="orders/:id" element={<OrderDetail />} />
          <Route path="articles" element={<Articles />} />
          <Route path="article-categories" element={<ArticleCategories />} />
          <Route path="coupons" element={<Coupons />} />
          <Route path="blacklists" element={<Blacklists />} />
          <Route path="logs" element={<Logs />} />
          <Route path="settings" element={<Settings />} />
          <Route path="api-tokens" element={<ApiTokens />} />
          <Route path="account" element={<Account />} />
          <Route path="notifications" element={<LegacyNotifications />} />
          <Route path="tasks" element={<TaskCenter />} />
                <Route path="refunds" element={<Refunds />} />
                <Route path="admins" element={<AdminAccounts />} />
                <Route path="operations" element={<Operations />} />
        </Route>
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </ConfigProvider>
  );
}
