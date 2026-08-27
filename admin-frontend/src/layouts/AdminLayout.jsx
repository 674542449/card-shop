import React, { useState, useEffect } from 'react';
import { Outlet, useNavigate, useLocation } from 'react-router-dom';
import { ProLayout } from '@ant-design/pro-components';
import {
  DashboardOutlined,
  FolderOutlined,
  ShoppingOutlined,
  FileTextOutlined,
  ReadOutlined,
  TagsOutlined,
  GiftOutlined,
  StopOutlined,
  HistoryOutlined,
  SettingOutlined,
  LogoutOutlined,
} from '@ant-design/icons';
import { Button, Dropdown, message, Space } from 'antd';
import { getMe, logout } from '../services/api';

const menuRoutes = {
  route: {
    routes: [
      {
        path: '/admin',
        name: '仪表盘',
        icon: <DashboardOutlined />,
      },
      {
        path: '/admin/categories',
        name: '分类管理',
        icon: <FolderOutlined />,
      },
      {
        path: '/admin/products',
        name: '商品管理',
        icon: <ShoppingOutlined />,
      },
      {
        path: '/admin/orders',
        name: '订单管理',
        icon: <FileTextOutlined />,
      },
      {
        path: '/admin/articles',
        name: '文章管理',
        icon: <ReadOutlined />,
      },
      {
        path: '/admin/article-categories',
        name: '文章分类',
        icon: <TagsOutlined />,
      },
      {
        path: '/admin/coupons',
        name: '优惠券管理',
        icon: <GiftOutlined />,
      },
      {
        path: '/admin/blacklists',
        name: '黑名单管理',
        icon: <StopOutlined />,
      },
      {
        path: '/admin/logs',
        name: '操作日志',
        icon: <HistoryOutlined />,
      },
      {
        path: '/admin/settings',
        name: '系统设置',
        icon: <SettingOutlined />,
      },
    ],
  },
};

export default function AdminLayout() {
  const navigate = useNavigate();
  const location = useLocation();
  const [adminInfo, setAdminInfo] = useState(null);

  useEffect(() => {
    getMe()
      .then((res) => setAdminInfo(res.data?.data || res.data))
      .catch(() => navigate('/admin/login', { replace: true }));
  }, [navigate]);

  const handleLogout = async () => {
    try {
      await logout();
      message.success('已退出登录');
      navigate('/admin/login', { replace: true });
    } catch {
      navigate('/admin/login', { replace: true });
    }
  };

  const dropdownItems = {
    items: [
      {
        key: 'logout',
        icon: <LogoutOutlined />,
        label: '退出登录',
        onClick: handleLogout,
      },
    ],
  };

  return (
    <ProLayout
      title="Card Shop 管理后台"
      logo={null}
      layout="mix"
      fixSiderbar
      fixedHeader
      {...menuRoutes}
      location={{ pathname: location.pathname }}
      menuItemRender={(item, dom) => (
        <a
          onClick={(e) => {
            e.preventDefault();
            navigate(item.path);
          }}
        >
          {dom}
        </a>
      )}
      breadcrumbRender={(routes) => routes}
      actionsRender={() => [
        <Dropdown key="user" menu={dropdownItems} placement="bottomRight">
          <Space style={{ cursor: 'pointer' }}>
            <span>{adminInfo?.username || '管理员'}</span>
          </Space>
        </Dropdown>,
      ]}
    >
      <Outlet />
    </ProLayout>
  );
}
