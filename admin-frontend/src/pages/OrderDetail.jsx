import React, { useEffect, useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { ProCard, ProDescriptions } from '@ant-design/pro-components';
import { Button, Tag, Spin, message, Popconfirm, Space, Typography } from 'antd';
import { ArrowLeftOutlined } from '@ant-design/icons';
import dayjs from 'dayjs';
import { getOrder, closeOrder, markPaid, resendOrder } from '../services/api';

const { Paragraph } = Typography;

// Timestamps arrive as UTC ISO strings; rendering them raw showed a time 8 hours
// behind the app's Asia/Shanghai clock.
const fmt = (v) => (v ? dayjs(v).format('YYYY-MM-DD HH:mm:ss') : '-');

const statusMap = {
  pending: { text: '待支付', color: 'orange' },
  paid: { text: '已支付', color: 'green' },
  closed: { text: '已关闭', color: 'default' },
  expired: { text: '已过期', color: 'red' },
};

// Kept in step with the values the backend really stores.
const paymentMethodMap = {
  alipay: '支付宝',
  wechat: '微信支付',
  usdt_trc20: 'USDT (TRC20)',
  usdt_bep20: 'USDT (BEP20)',
  usdt_polygon: 'USDT (Polygon)',
  manual: '人工确认',
};

export default function OrderDetail() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [order, setOrder] = useState(null);
  const [loading, setLoading] = useState(true);
  const [actionLoading, setActionLoading] = useState(false);

  const fetchOrder = async () => {
    setLoading(true);
    try {
      const res = await getOrder(id);
      setOrder(res.data?.data || res.data);
    } catch {
      message.error('获取订单信息失败');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchOrder();
  }, [id]);

  const handleClose = async () => {
    setActionLoading(true);
    try {
      await closeOrder(id);
      message.success('订单已关闭');
      fetchOrder();
    } catch (err) {
      message.error(err.response?.data?.message || '操作失败');
    } finally {
      setActionLoading(false);
    }
  };

  const handleMarkPaid = async () => {
    setActionLoading(true);
    try {
      await markPaid(id);
      message.success('已标记为已支付');
      fetchOrder();
    } catch (err) {
      message.error(err.response?.data?.message || '操作失败');
    } finally {
      setActionLoading(false);
    }
  };

  const handleResend = async () => {
    setActionLoading(true);
    try {
      await resendOrder(id);
      message.success('发送成功');
    } catch (err) {
      message.error(err.response?.data?.message || '操作失败');
    } finally {
      setActionLoading(false);
    }
  };

  if (loading) {
    return (
      <div style={{ textAlign: 'center', padding: 100 }}>
        <Spin size="large" />
      </div>
    );
  }

  if (!order) return null;

  const s = statusMap[order.status];

  return (
    <div>
      <Space style={{ marginBottom: 16 }}>
        <Button icon={<ArrowLeftOutlined />} onClick={() => navigate('/admin/orders')}>
          返回订单列表
        </Button>
        {order.status === 'pending' && (
          <>
            <Popconfirm title="确认关闭此订单？" onConfirm={handleClose}>
              <Button loading={actionLoading}>关闭订单</Button>
            </Popconfirm>
            <Popconfirm title="确认标记为已支付？" onConfirm={handleMarkPaid}>
              <Button type="primary" loading={actionLoading}>
                标记已支付
              </Button>
            </Popconfirm>
          </>
        )}
        {order.status === 'paid' && (
          <Popconfirm title="确认重新发送邮件？" onConfirm={handleResend}>
            <Button loading={actionLoading}>重新发送</Button>
          </Popconfirm>
        )}
      </Space>

      <ProCard title="订单信息" style={{ marginBottom: 16 }}>
        <ProDescriptions column={2}>
          <ProDescriptions.Item label="订单号">{order.order_no}</ProDescriptions.Item>
          <ProDescriptions.Item label="状态">
            {s ? <Tag color={s.color}>{s.text}</Tag> : order.status}
          </ProDescriptions.Item>
          <ProDescriptions.Item label="商品">{order.product?.name || '-'}</ProDescriptions.Item>
          <ProDescriptions.Item label="数量">{order.quantity}</ProDescriptions.Item>
          <ProDescriptions.Item label="单价">¥{order.unit_price}</ProDescriptions.Item>
          <ProDescriptions.Item label="总金额">¥{order.total_amount}</ProDescriptions.Item>
          <ProDescriptions.Item label="邮箱">{order.email}</ProDescriptions.Item>
          <ProDescriptions.Item label="支付方式">
            {paymentMethodMap[order.payment_method] || order.payment_method || '-'}
          </ProDescriptions.Item>
          {/* orders has no coupon_code column; the controller eager-loads the relation. */}
          <ProDescriptions.Item label="优惠券">{order.coupon?.code || '-'}</ProDescriptions.Item>
          <ProDescriptions.Item label="优惠金额">¥{order.discount_amount || 0}</ProDescriptions.Item>
          <ProDescriptions.Item label="IP">{order.ip || '-'}</ProDescriptions.Item>
          <ProDescriptions.Item label="创建时间">{fmt(order.created_at)}</ProDescriptions.Item>
          <ProDescriptions.Item label="支付时间">{fmt(order.paid_at)}</ProDescriptions.Item>
        </ProDescriptions>
      </ProCard>

      {order.status === 'paid' && order.cards && order.cards.length > 0 && (
        <ProCard title="卡密信息">
          {order.cards.map((card, idx) => (
            <Paragraph key={idx} copyable style={{ marginBottom: 4 }}>
              {card.content || card}
            </Paragraph>
          ))}
        </ProCard>
      )}
    </div>
  );
}
