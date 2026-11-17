import React, { Suspense, useMemo } from 'react';
import { Outlet, Navigate, useNavigate, useLocation } from 'react-router-dom';
import { canVisit, permittedMenu } from '../permissions';
import { ProLayout, PageContainer } from '@ant-design/pro-components';
import { LogoutOutlined, UserOutlined, ExportOutlined, SafetyCertificateOutlined, MenuOutlined } from '@ant-design/icons';
import { Button, Dropdown, Skeleton, Space, message } from 'antd';
import { logout } from '../services/api';
import { ADMIN_BASE } from '../base';
import { menuTree, leafPaths, preloadPage, resolveRouteState } from '../navigation';

export function ShopMark({ className = 'admin-shop-mark' }) {
  return (
    <svg className={className} width="28" height="28" viewBox="0 0 28 28" fill="none" aria-hidden="true">
      <rect x="2" y="2" width="24" height="24" rx="7" fill="url(#adminBrandGrad)" />
      <path d="M8.5 10.5h11M8.5 14.5h7M8.5 18.5h4.5" stroke="#fff" strokeWidth="2" strokeLinecap="round" />
      <circle cx="18.5" cy="18" r="2.2" fill="#38bdf8" stroke="#fff" strokeWidth="1.4" />
      <defs>
        <linearGradient id="adminBrandGrad" x1="2" y1="2" x2="26" y2="26" gradientUnits="userSpaceOnUse">
          <stop stopColor="#0284c7" />
          <stop offset="1" stopColor="#2563eb" />
        </linearGradient>
      </defs>
    </svg>
  );
}

const PageSkeleton = () => (
  <div className="admin-page-skeleton">
    <Skeleton active paragraph={{ rows: 2 }} title={{ width: 180 }} />
    <Skeleton active paragraph={{ rows: 6 }} title={false} style={{ marginTop: 24 }} />
  </div>
);

export default function AdminLayout({ admin }) {
  const navigate = useNavigate();
  const location = useLocation();
  const visibleMenu = useMemo(() => permittedMenu(admin, menuTree), [admin]);
  const { selectedKey, meta } = useMemo(() => resolveRouteState(location.pathname), [location.pathname]);

  const handleLogout = async () => {
    try {
      await logout();
    } catch (err) {
      message.error(err.response?.data?.message || '退出登录失败，请稍后重试。当前会话仍处于有效状态。');
      return;
    }
    message.success('已安全退出登录');
    window.location.replace(`${ADMIN_BASE}/login`);
  };

  if (!canVisit(admin, location.pathname)) {
    const first = ['/', ...leafPaths].find((p) => canVisit(admin, p)) || '/account';
    return <Navigate to={first} replace />;
  }

  return (
    <ProLayout
      className="admin-shell"
      layout="side"
      siderWidth={232}
      fixSiderbar
      fixedHeader
      title="CardShop"
      logo={<ShopMark />}
      {...visibleMenu}
      location={{ pathname: location.pathname }}
      menuProps={{
        selectedKeys: selectedKey ? [selectedKey] : [],
        defaultOpenKeys: ['/trade', '/system'],
      }}
      token={{
        bgLayout: '#f4f7fb',
        sider: {
          colorMenuBackground: '#0f172a',
          colorTextMenu: '#94a3b8',
          colorTextMenuSelected: '#ffffff',
          colorTextMenuItemHover: '#f8fafc',
          colorBgMenuItemSelected: 'rgba(14, 165, 233, 0.18)',
          colorBgMenuItemHover: 'rgba(148, 163, 184, 0.1)',
          colorTextMenuTitle: '#f8fafc',
          colorTextMenuSecondary: '#64748b',
          colorMenuItemDivider: 'rgba(148, 163, 184, 0.14)',
        },
        header: { colorBgHeader: '#ffffff', colorHeaderTitle: '#0f172a' },
        pageContainer: { colorBgPageContainer: '#f4f7fb' },
      }}
      headerContentRender={(props) => (
        <div className="admin-workspace-label">
          {props.isMobile && (
            <button
              type="button"
              className="admin-mobile-nav-toggle"
              aria-label={props.collapsed ? '展开导航菜单' : '收起导航菜单'}
              aria-expanded={!props.collapsed}
              onClick={() => props.onCollapse?.(!props.collapsed)}
            >
              <MenuOutlined aria-hidden="true" />
            </button>
          )}
          <span className="admin-workspace-dot" aria-hidden="true" />
          <span className="admin-workspace-root">管理控制台</span>
          <span className="admin-header-divider" aria-hidden="true">/</span>
          <span className="admin-workspace-page">{meta.title || '控制台'}</span>
        </div>
      )}
      actionsRender={() => [
        <Button
          key="storefront"
          className="admin-storefront-link"
          href="/"
          target="_blank"
          rel="noopener noreferrer"
          icon={<ExportOutlined />}
        >
          访问前台
        </Button>,
      ]}
      menuItemRender={(item, dom) => (
        <a
          href={item.path === '/' ? ADMIN_BASE || '/' : `${ADMIN_BASE}${item.path}`}
          onMouseEnter={() => preloadPage(item.path)}
          onFocus={() => preloadPage(item.path)}
          onClick={(e) => {
            if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
            e.preventDefault();
            if (item.isMobile) item.onClick?.();
            navigate(item.path);
          }}
        >
          {dom}
        </a>
      )}
      breadcrumbRender={(routes = []) => routes}
      avatarProps={{
        icon: <UserOutlined />,
        size: 'small',
        title: admin?.username || '管理员',
        render: (_, dom) => (
          <Dropdown
            placement="bottomRight"
            trigger={['click']}
            menu={{
              items: [
                { key: 'account', icon: <SafetyCertificateOutlined />, label: '账户与安全', onClick: () => navigate('/account') },
                { type: 'divider' },
                { key: 'logout', icon: <LogoutOutlined />, danger: true, label: '退出登录', onClick: handleLogout },
              ],
            }}
          >
            <button type="button" className="admin-user-menu" aria-label={`${admin?.username || '管理员'}的账户菜单`}>
              <Space size={8}>{dom}</Space>
            </button>
          </Dropdown>
        ),
      }}
      footerRender={() => (
        <footer className="admin-footer">
          <span>CardShop 管理控制台</span>
          <span>数字商品交易与自动履约管理系统</span>
        </footer>
      )}
    >
      <PageContainer
        className="admin-page-container"
        title={meta.title}
        content={meta.desc}
        breadcrumbRender={false}
      >
        <Suspense fallback={<PageSkeleton />}>
          <Outlet context={admin} />
        </Suspense>
      </PageContainer>
    </ProLayout>
  );
}
