import React, { useEffect, useRef, useState } from 'react';
import { useParams, useNavigate, useOutletContext } from 'react-router-dom';
import { ProCard, ProDescriptions } from '@ant-design/pro-components';
import {
  Alert,
  Button,
  Tag,
  Spin,
  message,
  Popconfirm,
  Space,
  Typography,
  Result,
  Table,
  Modal,
  Input,
  InputNumber,
  Select,
  Tabs,
  Empty,
} from 'antd';
import { ArrowLeftOutlined, ReloadOutlined, SyncOutlined } from '@ant-design/icons';
import api, { getOrder, closeOrder, markPaid, resendOrder } from '../services/api';
import { allows, canCapability } from '../permissions';
import {
  initializationStates,
  initializationErrors,
  initializationBlocksClosing,
} from '../utils/paymentInitialization';
import {
  ORDER_STATUS_MAP,
  PAYMENT_METHOD_MAP,
  REFUND_STATUS_MAP,
  NOTIFICATION_TYPES,
  NOTIFICATION_STATUSES,
  formatDateTime,
} from '../utils/orderMeta';

const { Paragraph } = Typography;

function createRequestUuid() {
  if (typeof globalThis.crypto?.randomUUID === 'function') {
    return globalThis.crypto.randomUUID();
  }
  const bytes = new Uint8Array(16);
  if (typeof globalThis.crypto?.getRandomValues === 'function') {
    globalThis.crypto.getRandomValues(bytes);
  } else {
    for (let i = 0; i < 16; i += 1) {
      bytes[i] = Math.floor(Math.random() * 256);
    }
  }
  bytes[6] = (bytes[6] & 0x0f) | 0x40;
  bytes[8] = (bytes[8] & 0x3f) | 0x80;
  const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

export default function OrderDetail() {
  const { id } = useParams();
  return <OrderDetailPage key={id} id={id} />;
}

function OrderDetailPage({ id }) {
  const admin = useOutletContext();
  const write = allows(admin, 'orders', 'write');
  const refundWrite = canCapability(admin, 'orders.refund');
  const confirmPayment = canCapability(admin, 'orders.mark_paid');
  const replacementWrite = canCapability(admin, 'orders.replace_cards');
  const navigate = useNavigate();
  const [order, setOrder] = useState(null);
  const [loading, setLoading] = useState(true);
  const [actionLoading, setActionLoading] = useState(false);
  const [loadError, setLoadError] = useState('');
  const [refundOpen, setRefundOpen] = useState(false);
  const [refundAmount, setRefundAmount] = useState(null);
  const [refundReason, setRefundReason] = useState('');
  const [refundReceipt, setRefundReceipt] = useState('primary');
  const [refundSubmitting, setRefundSubmitting] = useState(false);
  const [resolveReceipt, setResolveReceipt] = useState(null);
  const [resolveNote, setResolveNote] = useState('');
  const [resolveSubmitting, setResolveSubmitting] = useState(false);
  const [replacementOpen, setReplacementOpen] = useState(false);
  const [replacementIds, setReplacementIds] = useState([]);
  const [replacementReason, setReplacementReason] = useState('');
  const [replacementToken, setReplacementToken] = useState('');
  const [replacing, setReplacing] = useState(false);
  const loadRequest = useRef(null);

  const fetchOrder = async () => {
    loadRequest.current?.abort();
    const controller = new AbortController();
    loadRequest.current = controller;
    setLoading(true);
    setLoadError('');
    setOrder(null);
    try {
      const res = await getOrder(id, { signal: controller.signal });
      if (!controller.signal.aborted) setOrder(res.data?.data || res.data);
    } catch (err) {
      if (!controller.signal.aborted) {
        setLoadError(err.response?.status === 404 ? '订单不存在或已不可访问' : '加载订单详情失败，请稍后重试');
      }
    } finally {
      if (!controller.signal.aborted) setLoading(false);
    }
  };

  useEffect(() => {
    fetchOrder();
    return () => loadRequest.current?.abort();
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
      const res = await markPaid(id);
      message.success(res.data?.message || '订单已标记为已支付并发货');
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
      const response = await resendOrder(id);
      message.success(response.data.message);
      fetchOrder();
    } catch (err) {
      message.error(err.response?.data?.message || '操作失败');
    } finally {
      setActionLoading(false);
    }
  };

  const handleSyncGateway = async () => {
    setActionLoading(true);
    try {
      const r = await api.post(`/orders/${id}/sync`);
      message.success(r.data.message);
      fetchOrder();
    } catch (e) {
      message.error(e.response?.data?.message || '同步网关状态失败');
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

  if (!order) {
    return (
      <Result
        status="warning"
        title={loadError || '订单不可用'}
        extra={
          <Space>
            <Button onClick={() => navigate('/orders')}>返回订单列表</Button>
            <Button type="primary" onClick={fetchOrder}>重试</Button>
          </Space>
        }
      />
    );
  }

  const s = ORDER_STATUS_MAP[order.status];
  const initialization = initializationStates[order.payment_initialization];
  const receipts = order.payment_receipts || [];
  const notifications = order.notifications || [];
  const refunds = order.refunds || [];
  const replacements = order.card_replacements || [];

  const detailTabs = [
    {
      key: 'receipts',
      label: `付款回执 (${receipts.length})`,
      children: (
        <Table
          size="small"
          rowKey="id"
          pagination={false}
          dataSource={receipts}
          scroll={{ x: 760 }}
          locale={{ emptyText: <Empty description="暂无网关付款回执记录" image={Empty.PRESENTED_IMAGE_SIMPLE} /> }}
          columns={[
            { title: '收款通道', dataIndex: 'channel', width: 100 },
            { title: '网关流水号', dataIndex: 'trade_no', width: 180, render: (v) => <span className="admin-mono">{v || '-'}</span> },
            { title: '登记金额', dataIndex: 'amount', width: 120, render: (value) => <span className="admin-amount">{value} CNY</span> },
            { title: '接收时间', dataIndex: 'received_at', width: 170, render: formatDateTime },
            {
              title: '链上实付金额',
              width: 140,
              render: (_, r) => (r.actual_amount ? `${r.actual_amount} ${r.currency}` : '-'),
            },
            {
              title: '网络 / 交易哈希',
              ellipsis: true,
              render: (_, r) => `${r.network || '-'} / ${r.transaction_hash || '-'}`,
            },
            {
              title: '核对状态',
              width: 180,
              render: (_, r) =>
                r.review_reason ? (
                  r.review_resolved_at ? (
                    <Tag color="green">已处理：{r.resolution_note}</Tag>
                  ) : (
                    <Tag color="orange">{r.review_reason}</Tag>
                  )
                ) : (
                  <Tag color="green">正常</Tag>
                ),
            },
            {
              title: '操作',
              width: 130,
              render: (_, r) =>
                write && r.review_reason && !r.review_resolved_at ? (
                  <Button
                    size="small"
                    onClick={() => {
                      setResolveReceipt(r);
                      setResolveNote('');
                    }}
                  >
                    记录核对结果
                  </Button>
                ) : null,
            },
          ]}
        />
      ),
    },
    {
      key: 'notifications',
      label: `通知投递 (${notifications.length})`,
      children: (
        <Table
          size="small"
          rowKey="id"
          pagination={false}
          dataSource={notifications}
          scroll={{ x: 700 }}
          locale={{ emptyText: <Empty description="暂无通知投递记录" image={Empty.PRESENTED_IMAGE_SIMPLE} /> }}
          columns={[
            {
              title: '通知类型',
              dataIndex: 'type',
              width: 140,
              render: (value) => NOTIFICATION_TYPES[value] || value,
            },
            {
              title: '状态',
              dataIndex: 'status',
              width: 165,
              render: (value) => NOTIFICATION_STATUSES[value] || value,
            },
            { title: '尝试次数', dataIndex: 'attempts', width: 90 },
            { title: '异常说明', dataIndex: 'last_error', ellipsis: true },
            {
              title: '下次执行时间',
              dataIndex: 'available_at',
              width: 170,
              render: (value, record) => (record.status === 'pending' ? formatDateTime(value) : '-'),
            },
          ]}
        />
      ),
    },
    {
      key: 'refunds',
      label: `退款记录 (${refunds.length})`,
      children: (
        <Table
          rowKey="id"
          size="small"
          pagination={false}
          dataSource={refunds}
          scroll={{ x: 600 }}
          locale={{ emptyText: <Empty description="暂无退款记录" image={Empty.PRESENTED_IMAGE_SIMPLE} /> }}
          columns={[
            { title: '退款金额', dataIndex: 'amount', width: 130, render: (v) => <span className="admin-amount">¥{v}</span> },
            {
              title: '处理状态',
              dataIndex: 'status',
              width: 140,
              render: (v) => {
                const rs = REFUND_STATUS_MAP[v];
                return rs ? <Tag color={rs.color}>{rs.text}</Tag> : v;
              },
            },
            { title: '申请原因', dataIndex: 'reason' },
            { title: '退款流水凭证', dataIndex: 'reference', render: (v) => v || '-' },
          ]}
        />
      ),
    },
    ...(replacements.length > 0
      ? [
          {
            key: 'replacements',
            label: `售后换卡历史 (${replacements.length})`,
            children: (
              <Table
                rowKey="id"
                size="small"
                pagination={{ pageSize: 10 }}
                dataSource={replacements}
                scroll={{ x: 600 }}
                columns={[
                  { title: '操作时间', dataIndex: 'created_at', width: 175, render: formatDateTime },
                  { title: '执行管理员', width: 130, render: (_, record) => record.admin?.username || '-' },
                  { title: '换卡原因', dataIndex: 'reason' },
                  {
                    title: '卡密编号变更',
                    render: (_, record) =>
                      record.items?.map((item) => `#${item.old_card_id} → #${item.new_card_id}`).join('；'),
                  },
                ]}
              />
            ),
          },
        ]
      : []),
  ];

  return (
    <div className="admin-order-detail">
      {order.status === 'pending' && initializationBlocksClosing(order.payment_initialization) && (
        <Alert
          type="warning"
          showIcon
          message={initialization?.text || '收银台创建结果待核对'}
          style={{ marginBottom: 14 }}
          description="当前收银台创建状态尚未明确。请先核对网关交易记录，切勿重复发起支付或关闭订单；确认款项实际到账后方可执行人工确认支付。"
        />
      )}
      {order.has_payment_review && (
        <Alert
          type="warning"
          showIcon
          message="存在待核对付款回执"
          description={
            order.status === 'paid'
              ? '该订单已完成发货，但仍存在额外收款回执待核对。请核查是否为重复付款并登记退款处理，切勿再次确认支付。'
              : '系统已收到付款回执但尚未自动履约。请核对网关实收金额与交易状态后再做人工处置。'
          }
          style={{ marginBottom: 14 }}
        />
      )}
      {order.reconciliation_error && (
        <Alert type="warning" showIcon message={`最近对账提示：${order.reconciliation_error}`} style={{ marginBottom: 14 }} />
      )}

      <div className="admin-action-bar">
        <Space wrap>
          <Button icon={<ArrowLeftOutlined />} onClick={() => navigate('/orders')}>
            返回订单列表
          </Button>
          <Button icon={<ReloadOutlined />} onClick={fetchOrder} loading={actionLoading}>
            刷新详情
          </Button>
        </Space>

        <Space wrap>
          <Button icon={<SyncOutlined />} disabled={!write} loading={actionLoading} onClick={handleSyncGateway}>
            同步网关付款状态
          </Button>
          {write &&
            order.status === 'pending' &&
            !initializationBlocksClosing(order.payment_initialization) &&
            !order.payment_no &&
            !receipts.length && (
              <Popconfirm title="确认关闭该未支付订单并释放锁定库存？" onConfirm={handleClose}>
                <Button loading={actionLoading}>关闭订单</Button>
              </Popconfirm>
            )}
          {confirmPayment && ['pending', 'expired', 'closed'].includes(order.status) && (
            <Popconfirm
              title={
                order.status === 'pending'
                  ? '确认网关已实际收款并标记该订单为已支付？'
                  : '该订单已失效。请务必先在支付网关核实款项到账，确认后系统将重新分配卡密并执行发货。'
              }
              onConfirm={handleMarkPaid}
            >
              <Button type="primary" loading={actionLoading}>
                标记已支付
              </Button>
            </Popconfirm>
          )}
          {write && order.status === 'paid' && (
            <Popconfirm title="确认向买家邮箱重新发送卡密交付邮件？" onConfirm={handleResend}>
              <Button loading={actionLoading}>重新发送邮件</Button>
            </Popconfirm>
          )}
          {replacementWrite && order.status === 'paid' && (
            <Button
              disabled={Number(order.refund_balance?.reserved || 0) > 0}
              title={
                Number(order.refund_balance?.reserved || 0) > 0
                  ? '当前订单存在处理中的原付款退款申请，请先完成或驳回退款申请'
                  : undefined
              }
              onClick={() => {
                setReplacementIds([]);
                setReplacementReason('');
                setReplacementToken(createRequestUuid());
                setReplacementOpen(true);
              }}
            >
              售后换卡
            </Button>
          )}
          <Button
            disabled={!refundWrite || !order.refund_enabled}
            title={!order.refund_enabled ? '系统设置中尚未开启退款申请功能；既有退款可在退款管理中继续处理' : undefined}
            onClick={() => {
              setRefundAmount(order.refund_balance?.available || '0.00');
              setRefundReason('');
              setRefundReceipt('primary');
              setRefundOpen(true);
            }}
          >
            登记退款申请
          </Button>
        </Space>
      </div>

      <ProCard title="订单基础与结算信息" style={{ marginBottom: 16 }}>
        <ProDescriptions column={{ xs: 1, sm: 2, lg: 3 }}>
          <ProDescriptions.Item label="订单号">
            <span className="admin-order-number">{order.order_no}</span>
          </ProDescriptions.Item>
          <ProDescriptions.Item label="订单状态">
            {s ? <Tag color={s.color}>{s.text}</Tag> : order.status}
          </ProDescriptions.Item>
          <ProDescriptions.Item label="支付方式">
            {PAYMENT_METHOD_MAP[order.payment_method] || order.payment_method || '-'}
          </ProDescriptions.Item>
          <ProDescriptions.Item label="商品名称">
            {order.product_name || order.product?.name || '-'}
          </ProDescriptions.Item>
          <ProDescriptions.Item label="购买数量">{order.quantity}</ProDescriptions.Item>
          <ProDescriptions.Item label="成交单价">¥{order.unit_price}</ProDescriptions.Item>
          <ProDescriptions.Item label="订单总额">
            <span className="admin-amount">¥{order.total_amount}</span>
          </ProDescriptions.Item>
          <ProDescriptions.Item label="优惠券码">{order.coupon?.code || '-'}</ProDescriptions.Item>
          <ProDescriptions.Item label="优惠减免">¥{order.discount_amount || '0.00'}</ProDescriptions.Item>
          <ProDescriptions.Item label="联系邮箱">{order.email || '-'}</ProDescriptions.Item>
          <ProDescriptions.Item label="下单 IP">{order.ip || '-'}</ProDescriptions.Item>
          <ProDescriptions.Item label="网关交易号">{order.payment_no || '-'}</ProDescriptions.Item>
          <ProDescriptions.Item label="收银台创建状态">
            {initialization ? <Tag color={initialization.color}>{initialization.text}</Tag> : '暂无创建记录'}
          </ProDescriptions.Item>
          <ProDescriptions.Item label="状态更新时间">
            {formatDateTime(order.payment_initialization_detail?.updated_at)}
          </ProDescriptions.Item>
          <ProDescriptions.Item label="回执金额">
            {order.payment_received_amount ? `${order.payment_received_amount} CNY` : '-'}
          </ProDescriptions.Item>
          <ProDescriptions.Item label="创建时间">{formatDateTime(order.created_at)}</ProDescriptions.Item>
          <ProDescriptions.Item label="支付完成时间">{formatDateTime(order.paid_at)}</ProDescriptions.Item>
          <ProDescriptions.Item label="支付截止时间">{formatDateTime(order.expires_at)}</ProDescriptions.Item>
          <ProDescriptions.Item label="回执接收时间">{formatDateTime(order.payment_received_at)}</ProDescriptions.Item>
          <ProDescriptions.Item label="待核对原因">
            {order.payment_review_reason ||
              initializationErrors[order.payment_initialization_detail?.error_code] ||
              order.payment_initialization_detail?.error_code ||
              '-'}
          </ProDescriptions.Item>
        </ProDescriptions>
      </ProCard>

      {order.status === 'paid' && !order.cards_accessible && (
        <Alert
          type="info"
          showIcon
          message="当前会话无法解密读取已交付卡密"
          description="请重新登录管理员账户后刷新页面；若问题持续，请检查服务器密钥配置与日志。"
          style={{ marginBottom: 16 }}
        />
      )}

      {order.status === 'paid' && order.cards_accessible && order.cards && order.cards.length > 0 && (
        <ProCard title={`当前交付卡密（共 ${order.cards.length} 件）`} style={{ marginBottom: 16 }}>
          <div className="admin-secret-vault">
            {order.cards.map((card, idx) => (
              <div className="admin-secret-item" key={card.id || idx}>
                <Paragraph copyable style={{ marginBottom: 0 }}>
                  {card.content ?? ''}
                </Paragraph>
              </div>
            ))}
          </div>
        </ProCard>
      )}

      <div className="admin-surface-tabs">
        <Tabs defaultActiveKey="receipts" items={detailTabs} />
      </div>

      <Modal
        title="登记退款申请"
        open={refundOpen}
        confirmLoading={refundSubmitting}
        onCancel={() => !refundSubmitting && setRefundOpen(false)}
        onOk={async () => {
          if (refundSubmitting) return;
          setRefundSubmitting(true);
          try {
            await api.post(`/orders/${id}/refunds`, {
              amount: refundAmount,
              reason: refundReason,
              payment_receipt_id: refundReceipt === 'primary' ? null : refundReceipt,
            });
            message.success('退款申请已登记，请前往退款管理执行审核');
            setRefundOpen(false);
            fetchOrder();
          } catch (e) {
            message.error(e.response?.data?.message || '登记退款申请失败');
          } finally {
            setRefundSubmitting(false);
          }
        }}
      >
        <Typography.Paragraph type="secondary" style={{ marginBottom: 12 }}>
          请选择对应收款记录并填写退款金额（CNY）。审核通过后需在原支付渠道执行打款并回填流水凭证。
        </Typography.Paragraph>
        <Select
          style={{ width: '100%', marginBottom: 12 }}
          value={refundReceipt}
          onChange={(value) => {
            setRefundReceipt(value);
            const balance =
              value === 'primary'
                ? order.refund_balance
                : receipts.find((r) => r.id === value)?.refund_balance;
            setRefundAmount(balance?.available || '0.00');
          }}
          options={[
            { value: 'primary', label: `订单原付款 · 可退余额 ${order.refund_balance?.available || '0.00'} CNY` },
            ...receipts
              .filter((r) => r.trade_no !== order.payment_no)
              .map((r) => ({
                value: r.id,
                label: `${r.trade_no} · 可退余额 ${r.refund_balance?.available || '0.00'} CNY`,
              })),
          ]}
        />
        <InputNumber
          aria-label="退款金额"
          stringMode
          min="0.01"
          precision={2}
          max={
            refundReceipt === 'primary'
              ? order.refund_balance?.available
              : receipts.find((r) => r.id === refundReceipt)?.refund_balance?.available
          }
          value={refundAmount}
          onChange={setRefundAmount}
          style={{ width: '100%', marginBottom: 12 }}
        />
        <Input.TextArea
          aria-label="退款原因"
          rows={3}
          placeholder="请填写经核实的退款原因"
          value={refundReason}
          onChange={(e) => setRefundReason(e.target.value)}
        />
      </Modal>

      <Modal
        title="记录付款核对结果"
        open={!!resolveReceipt}
        confirmLoading={resolveSubmitting}
        onCancel={() => !resolveSubmitting && setResolveReceipt(null)}
        onOk={async () => {
          if (resolveSubmitting || !resolveReceipt) return;
          setResolveSubmitting(true);
          try {
            await api.post(`/orders/${id}/receipts/${resolveReceipt.id}/resolve`, { note: resolveNote });
            message.success('核对结果已保存');
            setResolveReceipt(null);
            fetchOrder();
          } catch (e) {
            message.error(e.response?.data?.message || '保存失败');
          } finally {
            setResolveSubmitting(false);
          }
        }}
      >
        <Input.TextArea
          rows={3}
          value={resolveNote}
          onChange={(e) => setResolveNote(e.target.value)}
          placeholder="请记录网关流水核对结论及实际处置措施"
        />
      </Modal>

      <Modal
        title="售后换卡"
        open={replacementOpen}
        confirmLoading={replacing}
        onCancel={() => !replacing && setReplacementOpen(false)}
        onOk={async () => {
          if (!replacementIds.length || replacementIds.length > 200 || !replacementReason.trim()) {
            message.warning('请选择 1 至 200 张需更换的卡密并填写换卡原因');
            return;
          }
          setReplacing(true);
          try {
            const res = await api.post(`/orders/${id}/replacements`, {
              card_ids: replacementIds,
              reason: replacementReason.trim(),
              request_token: replacementToken,
            });
            message.success(res.data.message);
            setReplacementOpen(false);
            fetchOrder();
          } catch (error) {
            message.error(error.response?.data?.message || '换卡执行失败，重试将沿用当前幂等令牌');
          } finally {
            setReplacing(false);
          }
        }}
      >
        <Alert
          type="warning"
          showIcon
          style={{ marginBottom: 16 }}
          message="执行换卡将消耗同商品可售库存；被替换的原卡密将永久移出当前交付且不会回退至可售库存。"
          description="换卡完成后，前台查单、TXT 导出、API 接口及重发邮件均更新为新卡密；此前已发出的邮件无法撤回。"
        />
        <Table
          rowKey="id"
          size="small"
          pagination={{ pageSize: 10 }}
          dataSource={order.cards || []}
          rowSelection={{ selectedRowKeys: replacementIds, onChange: setReplacementIds }}
          columns={[
            { title: '编号', dataIndex: 'id', width: 70 },
            { title: '当前卡密内容', dataIndex: 'content', ellipsis: true },
          ]}
        />
        <Input.TextArea
          aria-label="换卡原因"
          rows={3}
          value={replacementReason}
          onChange={(e) => setReplacementReason(e.target.value)}
          maxLength={2000}
          placeholder="请填写核实后的售后换卡原因"
          style={{ marginTop: 12 }}
        />
      </Modal>
    </div>
  );
}
