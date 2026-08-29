import React, { Suspense, useMemo } from 'react';
import { Outlet, useNavigate, useLocation } from 'react-router-dom';
import { ProLayout, PageContainer } from '@ant-design/pro-components';
import { LogoutOutlined, UserOutlined, ShopOutlined } from '@ant-design/icons';
import { Dropdown, Skeleton, Space, Typography, message } from 'antd';
import { logout } from '../services/api';
import { menuTree, leafPaths, preloadPage, pageMeta } from '../navigation';

/**
 * Shown in the content area while a page's chunk loads.
 *
 * A skeleton in the content area, not a full-screen spinner. The Suspense boundary
 * used to sit above <Routes>, so every navigation replaced the whole application —
 * sidebar, header and all — with a centred spinner on white, then rebuilt it. That
 * is what read as "每次点击栏目都白屏重新加载": nothing was actually reloading, but
 * the entire frame was being thrown away and redrawn on every click.
 *
 * The boundary lives around <Outlet /> now, so the frame stays put and only the
 * content changes. In practice this is rarely seen at all — the sidebar prefetches
 * the chunk on hover, so it has usually arrived before the click does.
 */
const PageSkeleton = () => (
  <div style={{ padding: 24, background: '#fff', borderRadius: 8 }}>
    <Skeleton active paragraph={{ rows: 2 }} title={{ width: 180 }} />
    <Skeleton active paragraph={{ rows: 6 }} title={false} style={{ marginTop: 24 }} />
  </div>
);

export default function AdminLayout({ admin }) {
  const navigate = useNavigate();
  const location = useLocation();

  // '/admin' prefixes every route, so left alone the 概览 entry stays highlighted
  // everywhere. Take the longest leaf that actually matches, which also keeps
  // 商品管理 lit on /admin/products/3/cards and 订单管理 on /admin/orders/9.
  const selectedKey = useMemo(() => {
    const path = location.pathname.replace(/\/+$/, '') || '/admin';
    return leafPaths.find((p) => path === p || path.startsWith(`${p}/`)) || '/admin';
  }, [location.pathname]);

  const meta = pageMeta[selectedKey] || {};

  const handleLogout = async () => {
    try {
      await logout();
    } catch (err) {
      // `finally` used to navigate to the login page whether or not the server
      // actually ended the session, so a failed request showed the operator a login
      // screen while their session cookie stayed valid — on a shared machine that
      // reads as "logged out" and is not. Stay put and say so instead.
      message.error(err.response?.data?.message || '退出登录失败，请重试。您仍处于登录状态。');
      return;
    }

    message.success('已退出登录');
    navigate('/admin/login', { replace: true });
  };

  return (
    <ProLayout
      layout="side"
      siderWidth={216}
      fixSiderbar
      fixedHeader
      title="卡密商城"
      logo={<ShopOutlined style={{ fontSize: 20, color: '#4db6ac' }} />}
      {...menuTree}
      location={{ pathname: location.pathname }}
      menuProps={{ selectedKeys: [selectedKey] }}
      // The sidebar wears the storefront's own header colour (#393d49) rather than a
      // generic dark grey, so the console reads as part of the same product as the
      // shop it runs. The content area stays on the shop's warm paper tone.
      token={{
        bgLayout: '#f5f2ee',
        sider: {
          colorMenuBackground: '#393d49',
          colorTextMenu: 'rgba(255,255,255,0.72)',
          colorTextMenuSelected: '#ffffff',
          colorTextMenuItemHover: '#ffffff',
          colorBgMenuItemSelected: 'rgba(0,150,136,0.28)',
          colorBgMenuItemHover: 'rgba(255,255,255,0.08)',
          colorTextMenuTitle: '#ffffff',
          colorTextMenuSecondary: 'rgba(255,255,255,0.45)',
          colorMenuItemDivider: 'rgba(255,255,255,0.08)',
        },
        header: { colorBgHeader: '#ffffff' },
      }}
      menuItemRender={(item, dom) => (
        <a
          href={item.path}
          // Warm the page's chunk before the click. Hover for pointers, focus for
          // keyboard — a keyboard user must not be the only one who waits.
          onMouseEnter={() => preloadPage(item.path)}
          onFocus={() => preloadPage(item.path)}
          onClick={(e) => {
            // A real href keeps middle-click and "open in new tab" working; this
            // stops the plain left click from doing a full document load.
            if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
            e.preventDefault();
            navigate(item.path);
          }}
        >
          {dom}
        </a>
      )}
      // ProLayout builds these from the menu tree, so with the grouped sidebar a
      // breadcrumb finally says something ("交易 / 订单管理"). On the old flat menu
      // it could only ever repeat the page title.
      breadcrumbRender={(routes = []) => routes}
      avatarProps={{
        // `dom` already contains the avatar and the name that these props describe —
        // wrapping it next to a second <Avatar> is what put two user icons in the
        // corner. The render only adds the dropdown around it.
        icon: <UserOutlined />,
        size: 'small',
        title: admin?.username || '管理员',
        render: (_, dom) => (
          <Dropdown
            placement="topRight"
            menu={{
              items: [{ key: 'logout', icon: <LogoutOutlined />, label: '退出登录', onClick: handleLogout }],
            }}
          >
            <Space style={{ cursor: 'pointer' }}>{dom}</Space>
          </Dropdown>
        ),
      }}
      footerRender={() => (
        <Typography.Text type="secondary" style={{ display: 'block', textAlign: 'center', padding: '16px 0', fontSize: 12 }}>
          卡密商城管理后台
        </Typography.Text>
      )}
    >
      {/*
        One PageContainer here rather than one in each of the twelve pages: it gives
        every screen the same heading, subtitle and breadcrumb, and makes it
        impossible for a page's heading to drift away from its menu entry.
      */}
      <PageContainer title={meta.title} content={meta.desc} breadcrumbRender={false}>
        <Suspense fallback={<PageSkeleton />}>
          <Outlet />
        </Suspense>
      </PageContainer>
    </ProLayout>
  );
}
