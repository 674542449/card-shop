import React, { Suspense, useEffect, useState } from 'react';
import { Routes, Route, Navigate, useNavigate } from 'react-router-dom';
import { ConfigProvider, Spin } from 'antd';
import zhCN from 'antd/locale/zh_CN';
import AdminLayout from './layouts/AdminLayout';
import { lazyPage } from './navigation';
import { getMe } from './services/api';

const LoginPage = lazyPage('/admin/login');
const Dashboard = lazyPage('/admin');
const Categories = lazyPage('/admin/categories');
const Products = lazyPage('/admin/products');
const ProductCards = lazyPage('/admin/products/cards');
const Orders = lazyPage('/admin/orders');
const OrderDetail = lazyPage('/admin/orders/detail');
const Articles = lazyPage('/admin/articles');
const ArticleCategories = lazyPage('/admin/article-categories');
const Coupons = lazyPage('/admin/coupons');
const Blacklists = lazyPage('/admin/blacklists');
const Logs = lazyPage('/admin/logs');
const Settings = lazyPage('/admin/settings');

/**
 * The console's visual identity, in one place.
 *
 * Sourced from the storefront's own tokens rather than picked fresh, so the shop and
 * the console it is run from look like one product: #00796b is the storefront's
 * --teal-dark, #ffb800 its --warm-btn, #ff4400 its --price-color.
 *
 * The primary is the DARK teal, not the #009688 the shop uses for buttons. antd puts
 * white text on colorPrimary, and #009688 gives 3.67:1 against white — under the
 * 4.5:1 WCAG AA needs for normal text. #00796b measures 5.34:1 and passes. The exact
 * shade is an accessibility result, not a preference.
 */
const theme = {
  token: {
    colorPrimary: '#00796b',
    colorLink: '#00796b',
    colorSuccess: '#16a34a',
    colorWarning: '#ffb800',
    colorError: '#dc2626',
    borderRadius: 6,
    colorBgLayout: '#f5f2ee',
    fontSize: 14,
  },
  components: {
    // Dense by default: this is a console for reading tables of orders and card
    // stock, where rows per screen is the thing that matters most.
    Table: { headerBg: '#f2f2f2', headerColor: '#333', cellPaddingBlock: 10 },
    Card: { headerFontSize: 15 },
    Menu: { itemMarginInline: 8 },
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
        navigate('/admin/login', { replace: true });
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
          path="/admin/login"
          element={
            <Suspense fallback={<FullPageSpin />}>
              <LoginPage />
            </Suspense>
          }
        />
        {/*
          Must be "/admin", not a splat pattern. A splat segment has to be the last
          thing in a pattern, so nested children under a splat parent compile to a
          path with the splat in the middle, which never matches.

          Note there is NO Suspense here. The one around the child pages lives inside
          AdminLayout, wrapped around <Outlet /> — putting it at this level is what
          made every navigation blank the whole application.
        */}
        <Route path="/admin" element={<RequireAuth>{(admin) => <AdminLayout admin={admin} />}</RequireAuth>}>
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
        </Route>
        <Route path="*" element={<Navigate to="/admin" replace />} />
      </Routes>
    </ConfigProvider>
  );
}
