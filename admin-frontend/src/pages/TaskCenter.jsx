import React, { useRef } from 'react';
import { Tabs, Alert, Button, message, Empty, Popconfirm, Tag } from 'antd';
import { ProTable } from '@ant-design/pro-components';
import { useOutletContext, useSearchParams } from 'react-router-dom';
import Link from '../components/PermissionLink';
import useWritePermission from '../hooks/useWritePermission';
import { allows, canCapability } from '../permissions';
import api, { getNotifications, retryNotification } from '../services/api';
import { formatDateTime, NOTIFICATION_STATUSES, NOTIFICATION_TYPES } from '../utils/orderMeta';

function NotificationsPanel() {
  const canWrite = useWritePermission('notifications');
  const actionRef = useRef();
  const [params] = useSearchParams();
  const status = params.get('status');

  return (
    <>
      <Alert
        showIcon
        type="info"
        style={{ marginBottom: 16 }}
        message="通知投递与重试机制"
        description="投递失败的任务将按退避策略自动重试（最多 5 次）。修复 SMTP 或 Telegram 配置后可手动重新入队；状态显示“已交给发送服务”表示上游通道已受理请求。"
      />
      <ProTable
        key={NOTIFICATION_STATUSES[status] ? status : 'all'}
        rowKey="id"
        actionRef={actionRef}
        form={{
          name: 'notifications-search',
          initialValues: { status: NOTIFICATION_STATUSES[status] ? status : undefined },
        }}
        scroll={{ x: 1080 }}
        columns={[
          { title: 'ID', dataIndex: 'id', search: false, width: 70 },
          { title: '通知类型', dataIndex: 'type', valueType: 'select', valueEnum: NOTIFICATION_TYPES, width: 145 },
          {
            title: '关联业务对象',
            search: false,
            width: 220,
            render: (_, record) =>
              record.order ? (
                <Link className="admin-order-number" to={`/orders/${record.order.id}`}>
                  {record.order.order_no}
                </Link>
              ) : record.product ? (
                <Link to={`/products/${record.product.id}/cards`}>{record.product.name}</Link>
              ) : (
                '-'
              ),
          },
          {
            title: '投递状态',
            dataIndex: 'status',
            valueType: 'select',
            valueEnum: NOTIFICATION_STATUSES,
            width: 155,
            render: (_, record) => (
              <Tag color={record.status === 'failed' ? 'red' : record.status === 'sent' ? 'green' : record.status === 'processing' ? 'blue' : 'default'}>
                {NOTIFICATION_STATUSES[record.status] || record.status}
              </Tag>
            ),
          },
          { title: '尝试次数', dataIndex: 'attempts', search: false, width: 90 },
          { title: '最近错误信息', dataIndex: 'last_error', search: false, width: 260, ellipsis: true },
          {
            title: '下次执行时间',
            dataIndex: 'available_at',
            search: false,
            width: 175,
            render: (_, record) => (record.status === 'pending' ? formatDateTime(record.available_at) : '-'),
          },
          {
            title: '操作',
            valueType: 'option',
            width: 105,
            render: (_, record) =>
              canWrite && ['failed', 'skipped'].includes(record.status) ? (
                <Popconfirm
                  title="确认将该通知重新加入发送队列？"
                  onConfirm={async () => {
                    try {
                      await retryNotification(record.id);
                      message.success('任务已重新入队');
                      actionRef.current?.reload();
                    } catch (error) {
                      message.error(error.response?.data?.message || '重试失败');
                    }
                  }}
                >
                  <Button type="link" htmlType="button" style={{ padding: 0, height: 'auto' }}>
                    重新入队
                  </Button>
                </Popconfirm>
              ) : null,
          },
        ]}
        request={async ({ current, pageSize, ...filters }) => {
          const response = await getNotifications({ page: current, per_page: pageSize, ...filters });
          return { data: response.data.data, total: response.data.total, success: true };
        }}
      />
    </>
  );
}

export default function TaskCenter() {
  const admin = useOutletContext();
  const [params, setParams] = useSearchParams();
  const notifications = allows(admin, 'notifications');
  const content = allows(admin, 'content');
  const contentWrite = allows(admin, 'content', 'write');
  const reconciliation = canCapability(admin, 'reconciliation.read');
  const reconciliationWrite = canCapability(admin, 'reconciliation.retry');
  const seoRef = useRef();
  const reconciliationRef = useRef();

  const failure = (e) => e.response?.data?.message || '请求失败，请检查网络连接后重试。';
  const run = async (action, success = '操作已完成') => {
    try {
      await action();
      if (success) message.success(success);
    } catch (e) {
      message.error(failure(e));
    }
  };

  const items = [
    {
      key: 'notifications',
      label: '通知投递',
      children: <NotificationsPanel />,
    },
    {
      key: 'reconciliation',
      label: '付款对账任务',
      children: (
        <>
          <Alert
            type="info"
            showIcon
            message="自动对账仅向支付网关查询既有交易结果，不会重复发起扣款。重试失败任务前，请确认网关参数有效且已启用自动付款对账。"
            style={{ marginBottom: 16 }}
          />
          <ProTable
            key={params.get('status') || 'all'}
            scroll={{ x: 'max-content' }}
            form={{
              name: 'reconciliation-search',
              initialValues: {
                status: ['pending', 'processing', 'completed', 'failed'].includes(params.get('status'))
                  ? params.get('status')
                  : undefined,
              },
            }}
            actionRef={reconciliationRef}
            rowKey="id"
            pagination={{ defaultPageSize: 20, showSizeChanger: true, pageSizeOptions: [20, 50, 100, 200] }}
            request={async (p) => {
              try {
                const r = await api.get('/maintenance/reconciliation-jobs', {
                  params: { page: p.current, per_page: p.pageSize, status: p.status },
                });
                return { data: r.data.data, total: r.data.total, success: true };
              } catch (e) {
                message.error(failure(e));
                return { data: [], total: 0, success: false };
              }
            }}
            columns={[
              { title: '任务 ID', dataIndex: 'id', search: false, width: 80 },
              {
                title: '关联订单',
                search: false,
                width: 210,
                render: (_, r) =>
                  r.order ? (
                    <Link className="admin-order-number" to={`/orders/${r.order_id}`}>
                      {r.order.order_no}
                    </Link>
                  ) : (
                    '订单不可用'
                  ),
              },
              {
                title: '任务状态',
                dataIndex: 'status',
                width: 120,
                valueType: 'select',
                valueEnum: { pending: '等待执行', processing: '查询中', completed: '核对完成', failed: '执行失败' },
                render: (_, r) => {
                  const map = {
                    pending: ['default', '等待执行'],
                    processing: ['blue', '查询中'],
                    completed: ['green', '核对完成'],
                    failed: ['red', '执行失败'],
                  }[r.status] || ['default', r.status];
                  return <Tag color={map[0]}>{map[1]}</Tag>;
                },
              },
              { title: '尝试次数', dataIndex: 'attempts', search: false, width: 90 },
              { title: '下次执行时间', dataIndex: 'available_at', search: false, width: 175, render: (_, r) => formatDateTime(r.available_at) },
              { title: '完成时间', dataIndex: 'finished_at', search: false, width: 175, render: (_, r) => formatDateTime(r.finished_at) },
              { title: '异常说明', dataIndex: 'last_error', search: false, ellipsis: true },
              {
                title: '操作',
                search: false,
                width: 90,
                render: (_, r) =>
                  reconciliationWrite && r.status === 'failed' ? (
                    <Button
                      size="small"
                      onClick={() =>
                        run(async () => {
                          await api.post(`/maintenance/reconciliation-jobs/${r.id}/retry`);
                          reconciliationRef.current?.reload();
                        }, '对账任务已重新入队')
                      }
                    >
                      重试
                    </Button>
                  ) : null,
              },
            ]}
          />
        </>
      ),
    },
    {
      key: 'seo',
      label: '搜索引擎推送',
      children: (
        <ProTable
          key={params.get('status') || 'all'}
          scroll={{ x: 'max-content' }}
          form={{
            name: 'seo-search',
            initialValues: {
              status: ['pending', 'processing', 'sent', 'failed'].includes(params.get('status'))
                ? params.get('status')
                : undefined,
            },
          }}
          actionRef={seoRef}
          rowKey="id"
          pagination={{ defaultPageSize: 20, showSizeChanger: true, pageSizeOptions: [20, 50, 100, 200] }}
          toolBarRender={() =>
            contentWrite
              ? [
                  <Button
                    key="enqueue"
                    type="primary"
                    onClick={() =>
                      run(async () => {
                        const r = await api.post('/seo-deliveries/enqueue');
                        message.info(r.data.message);
                        seoRef.current?.reload();
                      }, '')
                    }
                  >
                    提交全站公开页面
                  </Button>,
                ]
              : []
          }
          request={async (p) => {
            try {
              const r = await api.get('/seo-deliveries', {
                params: { page: p.current, per_page: p.pageSize, status: p.status },
              });
              return { data: r.data.data, total: r.data.total, success: true };
            } catch (e) {
              message.error(failure(e));
              return { data: [], total: 0, success: false };
            }
          }}
          columns={[
            { title: '推送通道', dataIndex: 'provider', search: false, width: 110 },
            { title: '目标网址', dataIndex: 'url', search: false, ellipsis: true },
            {
              title: '推送状态',
              dataIndex: 'status',
              width: 115,
              valueType: 'select',
              valueEnum: { pending: '等待推送', processing: '发送中', sent: '已提交', failed: '推送失败' },
              render: (_, r) => {
                const map = {
                  pending: ['default', '等待推送'],
                  processing: ['blue', '发送中'],
                  sent: ['green', '已提交'],
                  failed: ['red', '推送失败'],
                }[r.status] || ['default', r.status];
                return <Tag color={map[0]}>{map[1]}</Tag>;
              },
            },
            { title: '尝试次数', dataIndex: 'attempts', search: false, width: 90 },
            { title: '异常说明', dataIndex: 'last_error', search: false, ellipsis: true },
            {
              title: '告警确认',
              search: false,
              width: 165,
              render: (_, r) => (r.health_acknowledged_at ? formatDateTime(r.health_acknowledged_at) : '未确认'),
            },
            {
              title: '操作',
              search: false,
              width: 90,
              render: (_, r) =>
                contentWrite && r.status === 'failed' ? (
                  <Button
                    size="small"
                    onClick={() =>
                      run(async () => {
                        await api.post(`/seo-deliveries/${r.id}/retry`);
                        seoRef.current?.reload();
                      })
                    }
                  >
                    重试
                  </Button>
                ) : null,
            },
          ]}
        />
      ),
    },
  ].filter((tab) => (tab.key === 'notifications' ? notifications : tab.key === 'seo' ? content : reconciliation));

  if (!items.length) return <Empty description="当前账户暂无可查看的队列任务" />;
  const active = items.some((tab) => tab.key === params.get('tab')) ? params.get('tab') : items[0].key;
  return (
    <div className="admin-surface-tabs">
      <Tabs activeKey={active} destroyOnHidden onChange={(key) => setParams({ tab: key })} items={items} />
    </div>
  );
}
