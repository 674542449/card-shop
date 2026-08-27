import React, { Suspense, lazy } from 'react';
import { Routes, Route, Navigate, useNavigate } from 'react-router-dom';
import { Spin } from 'antd';
import AdminLayout from './layouts/AdminLayout';
import { getMe } from './services/api';

const LoginPage = lazy(() => import('./pages/LoginPage'));
const Dashboard = lazy(() => import('./pages/Dashboard'));
const Categories = lazy(() => import('./pages/Categories'));
const Products = lazy(() => import('./pages/Products'));
const ProductCards = lazy(() => import('./pages/ProductCards'));
const Orders = lazy(() => import('./pages/Orders'));
const OrderDetail = lazy(() => import('./pages/OrderDetail'));
const Articles = lazy(() => import('./pages/Articles'));
const ArticleCategories = lazy(() => import('./pages/ArticleCategories'));
const Coupons = lazy(() => import('./pages/Coupons'));
const Blacklists = lazy(() => import('./pages/Blacklists'));
const Logs = lazy(() => import('./pages/Logs'));
const Settings = lazy(() => import('./pages/Settings'));

const Loading = () => (
  <div style={{ display: 'flex', justifyContent: 'center', alignItems: 'center', height: '100vh' }}>
    <Spin size="large" />
  </div>
);

function ProtectedRoute({ children }) {
  const [checking, setChecking] = React.useState(true);
  const [authed, setAuthed] = React.useState(false);
  const navigate = useNavigate();

  React.useEffect(() => {
    getMe()
      .then(() => {
        setAuthed(true);
        setChecking(false);
      })
      .catch(() => {
        setChecking(false);
        navigate('/admin/login', { replace: true });
      });
  }, [navigate]);

  if (checking) return <Loading />;
  if (!authed) return null;
  return children;
}

export default function App() {
  return (
    <Suspense fallback={<Loading />}>
      <Routes>
        <Route path="/admin/login" element={<LoginPage />} />
        {/*
          Must be "/admin", not a splat pattern. A splat segment has to be the last thing
          in a pattern, so nested children under a splat parent compile to a path with the
          splat in the middle, which never matches.
        */}
        <Route
          path="/admin"
          element={
            <ProtectedRoute>
              <AdminLayout />
            </ProtectedRoute>
          }
        >
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
    </Suspense>
  );
}
