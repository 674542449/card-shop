import Link from '../components/PermissionLink';
import useWritePermission from '../hooks/useWritePermission';
import React, { useRef } from 'react';
import { ProTable } from '@ant-design/pro-components';
import { Alert, Button, message, Popconfirm, Tag } from 'antd';
import { useSearchParams } from 'react-router-dom';
import dayjs from 'dayjs';
import { getNotifications, retryNotification } from '../services/api';

const statuses = { pending: '等待发送 / 重试', processing: '发送中', sent: '已交给发送服务', failed: '发送失败', skipped: '已跳过' };
const types = { order_email: '卡密邮件', new_order: '新订单通知', payment_review: '付款待核对', low_stock: '低库存预警' };
const format = (value) => value ? dayjs(value).format('YYYY-MM-DD HH:mm:ss') : '-';

export default function Notifications() {
  const canWrite = useWritePermission('notifications');
  const actionRef = useRef();
  const [params] = useSearchParams();
  const status = params.get('status');
  return <>
    <Alert showIcon type="info" style={{ marginBottom: 20 }} message="通知发送与重试" description="发送失败会自动重试，最多尝试 5 次。修正 SMTP 或 Telegram 配置后，可重新入队。已发送表示发送服务已受理，不代表邮件一定进入收件箱。" />
    <ProTable key={statuses[status] ? status : 'all'} rowKey="id" actionRef={actionRef} form={{ name: 'notifications-search', initialValues: { status: statuses[status] ? status : undefined } }} scroll={{ x: 1100 }}
      columns={[
        { title: 'ID', dataIndex: 'id', search: false, width: 70 },
        { title: '类型', dataIndex: 'type', valueType: 'select', valueEnum: types, width: 140 },
        { title: '关联内容', search: false, width: 230, render: (_, record) => record.order ? <Link to={`/orders/${record.order.id}`}>{record.order.order_no}</Link> : record.product ? <Link to={`/products/${record.product.id}/cards`}>{record.product.name}</Link> : '-' },
        { title: '状态', dataIndex: 'status', valueType: 'select', valueEnum: statuses, width: 160, render: (_, record) => <Tag color={record.status === 'failed' ? 'red' : record.status === 'sent' ? 'green' : 'default'}>{statuses[record.status]}</Tag> },
        { title: '尝试次数', dataIndex: 'attempts', search: false, width: 85 },
        { title: '错误', dataIndex: 'last_error', search: false, width: 280, ellipsis: true },
        { title: '下次尝试', dataIndex: 'available_at', search: false, width: 180, render: (_, record) => record.status === 'pending' ? format(record.available_at) : '-' },
        { title: '操作', valueType: 'option', width: 110, render: (_, record) => canWrite && ['failed', 'skipped'].includes(record.status) ? <Popconfirm title="重新加入发送队列？" onConfirm={async () => {
          try { await retryNotification(record.id); message.success('已重新入队'); actionRef.current?.reload(); }
          catch (error) { message.error(error.response?.data?.message || '重试失败'); }
        }}><Button type="link" htmlType="button">重新入队</Button></Popconfirm> : null },
      ]}
      request={async ({ current, pageSize, ...filters }) => {
        const response = await getNotifications({ page: current, per_page: pageSize, ...filters });
        return { data: response.data.data, total: response.data.total, success: true };
      }} />
  </>;
}
