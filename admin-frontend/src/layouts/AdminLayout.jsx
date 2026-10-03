import React, { Suspense, useMemo } from 'react';
import { Outlet, Navigate, useNavigate, useLocation } from 'react-router-dom';
import { canVisit, permittedMenu } from '../permissions';
import { ProLayout, PageContainer } from '@ant-design/pro-components';
import { LogoutOutlined, UserOutlined, ExportOutlined, SettingOutlined, MenuOutlined } from '@ant-design/icons';
import { Button, Dropdown, Skeleton, Space, message } from 'antd';
import { logout } from '../services/api';
import { ADMIN_BASE } from '../base';
import { menuTree, leafPaths, preloadPage, pageMeta } from '../navigation';

function ShopMark() {
  return (
    <svg className="admin-shop-mark" width="28" height="32" viewBox="0 0 28 32" fill="none" aria-hidden="true">
      <path d="M6 2.5h12l5.5 5.5v20A1.5 1.5 0 0 1 22 29.5H6A1.5 1.5 0 0 1 4.5 28V4A1.5 1.5 0 0 1 6 2.5Z" stroke="currentColor" strokeWidth="1.6" />
      <path d="M17.5 3v6H23M9 15h10M9 20h7" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" />
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

  // Resolve detail pages to their closest leaf so their parent stays selected.
  const selectedKey = useMemo(() => {
    const path = location.pathname.replace(/\/+$/, '') || '/';
    return leafPaths.find((p) => path === p || path.startsWith(`${p}/`)) || '/';
  }, [location.pathname]);
  const meta = pageMeta[selectedKey] || {};

  const handleLogout = async () => {
    try {
      await logout();
    } catch (err) {
      // A failed request must not show a login screen while the session is valid.
      message.error(err.response?.data?.message || '退出登录失败，请重试。您仍处于登录状态。');
      return;
    }
    message.success('已退出登录');
    // End the JavaScript lifetime as well as the server session. Lazy page modules
    // can otherwise retain unsaved owner credentials across a second login.
    window.location.replace(`${ADMIN_BASE}/login`);
  };

  if (!canVisit(admin, location.pathname)) {
    const first = ['/', ...leafPaths].find(p => canVisit(admin, p)) || '/account';
    return <Navigate to={first} replace />;
  }

  return (
    <ProLayout
      className="claude-admin"
      layout="side"
      siderWidth={236}
      fixSiderbar
      fixedHeader
      title="CardShop"
      logo={<ShopMark />}
      {...visibleMenu}
      location={{ pathname: location.pathname }}
      menuProps={{ selectedKeys: [selectedKey], defaultOpenKeys: ['/catalog', '/trade', '/content', '/system'] }}
      token={{
        bgLayout: '#faf9f5',
        sider: {
          colorMenuBackground: '#f4f2eb',
          colorTextMenu: '#666257',
          colorTextMenuSelected: '#914128',
          colorTextMenuItemHover: '#302e28',
          colorBgMenuItemSelected: '#f0e4d9',
          colorBgMenuItemHover: '#ebe7dd',
          colorTextMenuTitle: '#302e28',
          colorTextMenuSecondary: '#878174',
          colorMenuItemDivider: '#e4ded2',
        },
        header: { colorBgHeader: '#faf9f5', colorHeaderTitle: '#302e28' },
        pageContainer: { colorBgPageContainer: '#faf9f5' },
      }}
      headerContentRender={(props) => (
        <div className="admin-workspace-label">
          {props.isMobile && (
            <button
              type="button"
              className="admin-mobile-nav-toggle"
              aria-label={props.collapsed ? '打开导航菜单' : '关闭导航菜单'}
              aria-expanded={!props.collapsed}
              onClick={() => props.onCollapse?.(!props.collapsed)}
            >
              <MenuOutlined aria-hidden="true" />
            </button>
          )}
          <span className="admin-workspace-dot" />
          <span>商城工作台</span>
          <span className="admin-header-divider">/</span>
          <span className="admin-workspace-page">{meta.title || '管理后台'}</span>
        </div>
      )}
      actionsRender={() => [
        <Button key="storefront" className="admin-storefront-link" href="/" target="_blank" rel="noopener noreferrer" icon={<ExportOutlined />}>
          查看商城
        </Button>,
      ]}
      menuExtraRender={(props) => !props.collapsed && <div className="admin-sidebar-caption">你的商城，井然有序。</div>}
      menuFooterRender={(props) => !props?.collapsed && (
        <div className="admin-sidebar-note">
          <span className="admin-sidebar-note-label">经营工作台</span>
          <p>管理商品、订单与内容，<br />让每一次交付都顺畅。</p>
        </div>
      )}
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
                { key: 'account', icon: <SettingOutlined />, label: '账户与密码', onClick: () => navigate('/account') },
                { type: 'divider' },
                { key: 'logout', icon: <LogoutOutlined />, label: '退出登录', onClick: handleLogout },
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
        <footer className="admin-footer"><span>CardShop</span><span>专注每一笔订单，照顾每一次交付。</span></footer>
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
