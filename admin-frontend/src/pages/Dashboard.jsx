import React, { useEffect, useState } from 'react';
import { StatisticCard } from '@ant-design/pro-components';
import { Col, Row, Spin } from 'antd';
import {
  ShoppingCartOutlined,
  DollarOutlined,
  ShoppingOutlined,
  FileTextOutlined,
} from '@ant-design/icons';
import { getDashboard } from '../services/api';

export default function Dashboard() {
  const [loading, setLoading] = useState(true);
  const [data, setData] = useState({});

  useEffect(() => {
    getDashboard()
      .then((res) => setData(res.data?.data || res.data))
      .catch(() => {})
      .finally(() => setLoading(false));
  }, []);

  if (loading) {
    return (
      <div style={{ textAlign: 'center', padding: 100 }}>
        <Spin size="large" />
      </div>
    );
  }

  return (
    <div>
      <Row gutter={[16, 16]}>
        <Col xs={24} sm={12} lg={6}>
          <StatisticCard
            statistic={{
              title: '今日订单',
              value: data.today_orders ?? 0,
              icon: <ShoppingCartOutlined style={{ fontSize: 32, color: '#1890ff' }} />,
            }}
          />
        </Col>
        <Col xs={24} sm={12} lg={6}>
          <StatisticCard
            statistic={{
              title: '今日收入',
              value: data.today_revenue ?? 0,
              prefix: '¥',
              icon: <DollarOutlined style={{ fontSize: 32, color: '#52c41a' }} />,
            }}
          />
        </Col>
        <Col xs={24} sm={12} lg={6}>
          <StatisticCard
            statistic={{
              title: '商品总数',
              value: data.total_products ?? 0,
              icon: <ShoppingOutlined style={{ fontSize: 32, color: '#faad14' }} />,
            }}
          />
        </Col>
        <Col xs={24} sm={12} lg={6}>
          <StatisticCard
            statistic={{
              title: '订单总数',
              value: data.total_orders ?? 0,
              icon: <FileTextOutlined style={{ fontSize: 32, color: '#722ed1' }} />,
            }}
          />
        </Col>
      </Row>
    </div>
  );
}
