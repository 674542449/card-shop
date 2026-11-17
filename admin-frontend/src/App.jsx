import React, { Suspense, useEffect, useState } from 'react';
import { Routes, Route, Navigate, useNavigate, useLocation } from 'react-router-dom';
import { ConfigProvider, Spin } from 'antd';
import zhCN from 'antd/locale/zh_CN';
import AdminLayout from './layouts/AdminLayout';
import { lazyPage } from './navigation';
import { getMe } from './services/api';

const LoginPage = lazyPage('/login');
const Dashboard = lazyPage('/');
const Products = lazyPage('/products');
const ProductCards = lazyPage('/products/cards');
const Orders = lazyPage('/orders');
const OrderDetail = lazyPage('/orders/detail');
const Articles = lazyPage('/articles');
const Coupons = lazyPage('/coupons');
const Blacklists = lazyPage('/blacklists');
const Logs = lazyPage('/logs');
const Settings = lazyPage('/settings');
const ApiTokens = lazyPage('/api-tokens');
const Account = lazyPage('/account');
const TaskCenter = lazyPage('/tasks');
const Refunds = lazyPage('/refunds');
const Operations = lazyPage('/operations');

function TabRedirect({ to, tab }) {
  const { search } = useLocation();
  const params = new URLSearchParams(search);
  params.set('tab', tab);
  return <Navigate to={`${to}?${params.toString()}`} replace />;
}

/** Modern SaaS Admin Console tokens: crisp cool-slate surfaces & cobalt-cyan accents. */
const theme = {
  token: {
    colorPrimary: '#0284c7',
    colorLink: '#0284c7',
    colorLinkHover: '#0369a1',
    colorSuccess: '#059669',
    colorWarning: '#d97706',
    colorError: '#e11d48',
    colorInfo: '#0284c7',
    colorText: '#0f172a',
    colorTextSecondary: '#475569',
    colorTextTertiary: '#64748b',
    colorBgLayout: '#f4f7fb',
    colorBgContainer: '#ffffff',
    colorBgElevated: '#ffffff',
    colorBorder: '#cbd5e1',
    colorBorderSecondary: '#e2e8f0',
    colorFillAlter: '#f8fafc',
    borderRadius: 8,
    borderRadiusLG: 12,
    controlHeight: 36,
    fontSize: 14,
    fontFamily: '"Inter", -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", sans-serif',
    boxShadow: '0 8px 24px rgba(15, 23, 42, 0.08)',
    boxShadowSecondary: '0 16px 40px rgba(15, 23, 42, 0.12)',
  },
  components: {
    Table: {
      headerBg: '#f8fafc',
      headerColor: '#475569',
      rowHoverBg: '#f0f9ff',
      borderColor: '#e2e8f0',
      cellPaddingBlock: 12,
      headerBorderRadius: 8,
    },
    Card: { headerFontSize: 15, headerHeight: 52 },
    Button: { fontWeight: 500, primaryShadow: '0 2px 6px rgba(2, 132, 199, 0.22)', defaultShadow: 'none' },
    Input: { activeShadow: '0 0 0 3px rgba(2, 132, 199, 0.14)' },
    InputNumber: { activeShadow: '0 0 0 3px rgba(2, 132, 199, 0.14)' },
    Select: { optionSelectedBg: '#e0f2fe', optionSelectedColor: '#0369a1' },
    Menu: {
      itemMarginInline: 10,
      itemBorderRadius: 8,
      itemHeight: 38,
      subMenuItemBg: 'transparent',
    },
    Tabs: { inkBarColor: '#0284c7', itemSelectedColor: '#0284c7', itemHoverColor: '#0369a1' },
    Tag: { defaultBg: '#f1f5f9', defaultColor: '#475569' },
    Tooltip: { colorBgSpotlight: '#0f172a' },
    Modal: { headerBg: '#ffffff', contentBg: '#ffffff', footerBg: '#ffffff' },
    Drawer: { colorBgElevated: '#ffffff' },
    Skeleton: { color: '#e2e8f0', colorGradientEnd: '#f1f5f9' },
  },
};

const FullPageSpin = () => (
  <div style={{ display: 'flex', justifyContent: 'center', alignItems: 'center', minHeight: '100vh', background: '#f4f7fb' }}>
    <Spin size="large" />
  </div>
);

/** Resolves the admin session once and passes it down to AdminLayout. */
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
        <Route
          path="/login"
          element={
            <Suspense fallback={<FullPageSpin />}>
              <LoginPage />
            </Suspense>
          }
        />
        <Route path="/" element={<RequireAuth>{(admin) => <AdminLayout admin={admin} />}</RequireAuth>}>
          <Route index element={<Dashboard />} />
          <Route path="products" element={<Products />} />
          <Route path="products/:productId/cards" element={<ProductCards />} />
          <Route path="categories" element={<TabRedirect to="/products" tab="categories" />} />
          <Route path="orders" element={<Orders />} />
          <Route path="orders/:id" element={<OrderDetail />} />
          <Route path="refunds" element={<Refunds />} />
          <Route path="coupons" element={<Coupons />} />
          <Route path="articles" element={<Articles />} />
          <Route path="article-categories" element={<TabRedirect to="/articles" tab="categories" />} />
          <Route path="tasks" element={<TaskCenter />} />
          <Route path="notifications" element={<TabRedirect to="/tasks" tab="notifications" />} />
          <Route path="operations" element={<Operations />} />
          <Route path="settings" element={<Settings />} />
          <Route path="blacklists" element={<Blacklists />} />
          <Route path="api-tokens" element={<ApiTokens />} />
          <Route path="logs" element={<Logs />} />
          <Route path="account" element={<Account />} />
        </Route>
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </ConfigProvider>
  );
}
