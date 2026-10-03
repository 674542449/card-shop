import React, { useEffect, useRef, useState } from 'react';
import { useParams, useNavigate, useOutletContext } from 'react-router-dom';
import { ProCard, ProDescriptions } from '@ant-design/pro-components';
import { Alert, Button, Tag, Spin, message, Popconfirm, Space, Typography, Result, Table, Modal, Input, InputNumber, Select } from 'antd';
import { ArrowLeftOutlined } from '@ant-design/icons';
import dayjs from 'dayjs';
import api from '../services/api';
import {allows, canCapability} from '../permissions';
import { getOrder, closeOrder, markPaid, resendOrder } from '../services/api';
import { initializationStates, initializationErrors, initializationBlocksClosing } from '../utils/paymentInitialization';

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
  // A different order must own fresh data, dialogs and mutation state.
  return <OrderDetailPage key={id} id={id} />;
}

function OrderDetailPage({ id }) {
  const admin=useOutletContext();const write=allows(admin,'orders','write');const refundWrite=canCapability(admin,'orders.refund');
  const confirmPayment = canCapability(admin,'orders.mark_paid');
  const replacementWrite = canCapability(admin,'orders.replace_cards');
  const navigate = useNavigate();
  const [order, setOrder] = useState(null);
  const [loading, setLoading] = useState(true);
  const [actionLoading, setActionLoading] = useState(false);
  const [loadError, setLoadError] = useState('');
  const [refundOpen,setRefundOpen]=useState(false); const [refundAmount,setRefundAmount]=useState(null); const [refundReason,setRefundReason]=useState(''); const [refundReceipt,setRefundReceipt]=useState('primary');
  const [resolveReceipt,setResolveReceipt]=useState(null); const [resolveNote,setResolveNote]=useState('');
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
      if (!controller.signal.aborted) setLoadError(err.response?.status === 404 ? '订单不存在或已不可用' : '获取订单信息失败，请重试');
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
      const response = await resendOrder(id);
      message.success(response.data.message);
      fetchOrder();
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

  if (!order) return <Result status="warning" title={loadError || '订单不可用'} extra={<Space><Button onClick={() => navigate('/orders')}>返回订单列表</Button><Button onClick={fetchOrder}>重试</Button></Space>} />;

  const s = statusMap[order.status];
  const initialization = initializationStates[order.payment_initialization];

  return (
    <div>
      {order.status === 'pending' && initializationBlocksClosing(order.payment_initialization) && <Alert type="warning" showIcon
        message={initialization?.text || '付款创建待核对'} style={{ marginBottom: 16 }}
        description="收银台创建结果尚未确定。请核对原订单和网关记录，勿再次创建付款或关闭订单；此状态并不表示已经收到付款。确认实际到账后才能人工确认支付。" />}
      {order.has_payment_review && (
        <Alert
          type="warning"
          showIcon
          message="付款待核对"
          description={order.status === 'paid' ? '订单已经发货，但仍有额外收款待核对。请核对重复付款并处理相应退款，不要再次确认支付。' : '收到付款回执，但尚未发货。请核对网关金额及交易状态后人工处理，避免重复付款。'}
          style={{ marginBottom: 16 }}
        />
      )}
      <Space wrap style={{ marginBottom: 16 }}>
        <Button icon={<ArrowLeftOutlined />} onClick={() => navigate('/orders')}>
          返回订单列表
        </Button>
        {write && order.status === 'pending' && !initializationBlocksClosing(order.payment_initialization) && !order.payment_no && !order.payment_receipts?.length && (
          <Popconfirm title="确认关闭此订单？" onConfirm={handleClose}>
            <Button loading={actionLoading}>关闭订单</Button>
          </Popconfirm>
        )}
        {/* Also offered on expired and closed orders, because that is the case this
            button matters most in: a gateway payment that arrived after the order
            lapsed cannot be delivered automatically, and this is the only way to
            complete the sale. Restricted to pending, the repair existed in the API
            and was unreachable from the screen the operator is looking at. */}
        {confirmPayment && ['pending', 'expired', 'closed'].includes(order.status) && (
          <Popconfirm
            title={
              order.status === 'pending'
                ? '确认标记为已支付？'
                : '该订单已失效。确认在网关流水中查到这笔付款后再标记，系统会重新分配卡密并发货。'
            }
            onConfirm={handleMarkPaid}
          >
            <Button type="primary" loading={actionLoading}>
              标记已支付
            </Button>
          </Popconfirm>
        )}
        {write && order.status === 'paid' && (
          <Popconfirm title="确认重新发送邮件？" onConfirm={handleResend}>
            <Button loading={actionLoading}>重新发送</Button>
          </Popconfirm>
        )}
        {replacementWrite && order.status === 'paid' && (
          <Button disabled={Number(order.refund_balance?.reserved || 0) > 0} title={Number(order.refund_balance?.reserved || 0) > 0 ? '订单原付款退款正在处理，请先完成或拒绝退款申请' : undefined} onClick={() => {
            setReplacementIds([]); setReplacementReason('');
            setReplacementToken(window.crypto?.randomUUID?.() || 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
              const r = Math.floor(Math.random() * 16); return (c === 'x' ? r : (r & 3) | 8).toString(16);
            }));
            setReplacementOpen(true);
          }}>售后换卡</Button>
        )}
      </Space>

      <ProCard title="订单信息" style={{ marginBottom: 16 }}>
        <ProDescriptions column={2}>
          <ProDescriptions.Item label="订单号">{order.order_no}</ProDescriptions.Item>
          <ProDescriptions.Item label="状态">
            {s ? <Tag color={s.color}>{s.text}</Tag> : order.status}
          </ProDescriptions.Item>
          <ProDescriptions.Item label="商品">{order.product_name || order.product?.name || '-'}</ProDescriptions.Item>
          <ProDescriptions.Item label="数量">{order.quantity}</ProDescriptions.Item>
          <ProDescriptions.Item label="单价">¥{order.unit_price}</ProDescriptions.Item>
          <ProDescriptions.Item label="总金额">¥{order.total_amount}</ProDescriptions.Item>
          <ProDescriptions.Item label="邮箱">{order.email}</ProDescriptions.Item>
          <ProDescriptions.Item label="支付方式">
            {paymentMethodMap[order.payment_method] || order.payment_method || '-'}
          </ProDescriptions.Item>
          <ProDescriptions.Item label="收银台创建状态">{initialization ? <Tag color={initialization.color}>{initialization.text}</Tag> : '暂无创建记录'}</ProDescriptions.Item>
          <ProDescriptions.Item label="创建状态更新时间">{fmt(order.payment_initialization_detail?.updated_at)}</ProDescriptions.Item>
          <ProDescriptions.Item label="创建结果说明" span={2}>{initializationErrors[order.payment_initialization_detail?.error_code] || order.payment_initialization_detail?.error_code || '-'}</ProDescriptions.Item>
          {/* orders has no coupon_code column; the controller eager-loads the relation. */}
          <ProDescriptions.Item label="优惠券">{order.coupon?.code || '-'}</ProDescriptions.Item>
          <ProDescriptions.Item label="优惠金额">¥{order.discount_amount || 0}</ProDescriptions.Item>
          <ProDescriptions.Item label="IP">{order.ip || '-'}</ProDescriptions.Item>
          <ProDescriptions.Item label="网关交易号">{order.payment_no || '-'}</ProDescriptions.Item>
          <ProDescriptions.Item label="回执金额（人民币）">{order.payment_received_amount ? `${order.payment_received_amount} CNY` : '-'}</ProDescriptions.Item>
          <ProDescriptions.Item label="回执时间">{fmt(order.payment_received_at)}</ProDescriptions.Item>
          <ProDescriptions.Item label="待核对原因">{order.payment_review_reason || '-'}</ProDescriptions.Item>
          <ProDescriptions.Item label="创建时间">{fmt(order.created_at)}</ProDescriptions.Item>
          <ProDescriptions.Item label="支付时间">{fmt(order.paid_at)}</ProDescriptions.Item>
          <ProDescriptions.Item label="支付截止时间">{fmt(order.expires_at)}</ProDescriptions.Item>
        </ProDescriptions>
      </ProCard>

      <Space wrap style={{marginTop:16}}>
        <Button disabled={!write} onClick={async()=>{try{const r=await api.post(`/orders/${id}/sync`);message.success(r.data.message);fetchOrder();}catch(e){message.error(e.response?.data?.message||'对账失败');}}}>同步网关付款状态</Button>
        <Button disabled={!refundWrite || !order.refund_enabled} onClick={()=>{setRefundAmount(order.refund_balance?.available || '0.00');setRefundReason('');setRefundReceipt('primary');setRefundOpen(true);}}>登记退款申请</Button>
      </Space>
      {!order.refund_enabled && <Typography.Paragraph type="secondary" style={{marginTop:8}}>店主暂未开放新的退款申请；退款管理中的已有申请仍可继续处理。</Typography.Paragraph>}
      {order.reconciliation_error&&<Alert type="warning" message={order.reconciliation_error} style={{marginTop:12}} />}
      <Modal title="登记退款申请" open={refundOpen} onCancel={()=>setRefundOpen(false)} onOk={async()=>{try{await api.post(`/orders/${id}/refunds`,{amount:refundAmount,reason:refundReason,payment_receipt_id:refundReceipt==='primary'?null:refundReceipt});message.success('已登记，前往退款管理审核');setRefundOpen(false);fetchOrder();}catch(e){message.error(e.response?.data?.message||'登记失败');}}}>
        <p>选择退款对应的付款。金额按人民币订单金额登记；实际退款需在原渠道完成。</p>
        <Select style={{width:'100%',marginBottom:12}} value={refundReceipt} onChange={value => {
          setRefundReceipt(value);
          const balance = value === 'primary' ? order.refund_balance : order.payment_receipts?.find(r => r.id === value)?.refund_balance;
          setRefundAmount(balance?.available || '0.00');
        }} options={[{value:'primary',label:`订单原付款 · 可退 ${order.refund_balance?.available || '0.00'} CNY`},...(order.payment_receipts||[]).filter(r=>r.trade_no!==order.payment_no).map(r=>({value:r.id,label:`${r.trade_no} · 可退 ${r.refund_balance?.available || '0.00'} CNY`}))]} />
        <InputNumber aria-label="退款金额" stringMode min="0.01" precision={2} max={refundReceipt==='primary'?order.refund_balance?.available:order.payment_receipts?.find(r=>r.id===refundReceipt)?.refund_balance?.available} value={refundAmount} onChange={setRefundAmount} style={{width:'100%',marginBottom:12}} />
        <Input.TextArea aria-label="退款原因" placeholder="退款原因" value={refundReason} onChange={e=>setRefundReason(e.target.value)} />
      </Modal>
      <Modal title="完成付款核对" open={!!resolveReceipt} onCancel={()=>setResolveReceipt(null)} onOk={async()=>{try{await api.post(`/orders/${id}/receipts/${resolveReceipt.id}/resolve`,{note:resolveNote});message.success('核对结果已保存');setResolveReceipt(null);fetchOrder();}catch(e){message.error(e.response?.data?.message||'保存失败');}}}><Input.TextArea value={resolveNote} onChange={e=>setResolveNote(e.target.value)} placeholder="说明核对结果和实际处置" /></Modal>
      <Modal title="售后换卡" open={replacementOpen} confirmLoading={replacing} onCancel={() => !replacing && setReplacementOpen(false)} onOk={async () => {
        if (!replacementIds.length || replacementIds.length > 200 || !replacementReason.trim()) { message.warning('请选择 1 至 200 张卡密并填写换卡原因'); return; }
        setReplacing(true);
        try {
          const res = await api.post(`/orders/${id}/replacements`, { card_ids: replacementIds, reason: replacementReason.trim(), request_token: replacementToken });
          message.success(res.data.message); setReplacementOpen(false); fetchOrder();
        } catch (error) { message.error(error.response?.data?.message || '换卡失败，重试会沿用同一操作编号'); }
        finally { setReplacing(false); }
      }}>
        <Alert type="warning" showIcon style={{ marginBottom: 16 }} message="将消耗同商品的可售库存，原卡永久退出当前交付，保留历史且不会回到库存。" description="成功后买家查单、TXT、API 和后续邮件显示当前卡密；已发送的旧邮件无法撤回。" />
        <Table rowKey="id" size="small" pagination={{ pageSize: 10 }} dataSource={order.cards || []}
          rowSelection={{ selectedRowKeys: replacementIds, onChange: setReplacementIds }}
          columns={[{ title: '编号', dataIndex: 'id', width: 65 }, { title: '当前卡密', dataIndex: 'content', ellipsis: true }]} />
        <Input.TextArea aria-label="换卡原因" value={replacementReason} onChange={e => setReplacementReason(e.target.value)} maxLength={2000} placeholder="填写核实后的售后换卡原因" style={{ marginTop: 12 }} />
      </Modal>
      <ProCard title="退款记录" style={{marginTop:16}}><Table rowKey="id" size="small" pagination={false} dataSource={order.refunds||[]} columns={[{title:'金额（人民币）',dataIndex:'amount'},{title:'状态',dataIndex:'status'},{title:'原因',dataIndex:'reason'},{title:'凭证',dataIndex:'reference'}]} /></ProCard>
      <ProCard title="付款回执" style={{ marginTop: 16 }}>
        <Table size="small" rowKey="id" pagination={false} dataSource={order.payment_receipts || []} scroll={{ x: 600 }} columns={[
          { title: '渠道', dataIndex: 'channel' }, { title: '网关流水', dataIndex: 'trade_no' },
          { title: '订单金额（人民币）', dataIndex: 'amount', render: (value) => `${value} CNY` }, { title: '收到时间', dataIndex: 'received_at', render: fmt },
          {title:'实际代币金额',render:(_,r)=>r.actual_amount?`${r.actual_amount} ${r.currency}`:'-'},{title:'网络 / 交易哈希',render:(_,r)=>`${r.network||'-'} / ${r.transaction_hash||'-'}`},
          {title:'核对状态',render:(_,r)=>r.review_reason?(r.review_resolved_at?`已处理：${r.resolution_note}`:r.review_reason):'正常'},
          {title:'操作',render:(_,r)=>write&&r.review_reason&&!r.review_resolved_at?<Button onClick={()=>{setResolveReceipt(r);setResolveNote('');}}>记录核对结果</Button>:null},
        ]} />
      </ProCard>
      <ProCard title="通知投递" extra={<Button onClick={fetchOrder}>刷新状态</Button>} style={{ marginTop: 16 }}>
        <Table size="small" rowKey="id" pagination={false} dataSource={order.notifications || []} scroll={{ x: 700 }} columns={[
          { title: '类型', dataIndex: 'type', render: (value) => ({ order_email: '卡密邮件', refund_email: '退款处理邮件', new_order: '订单通知', payment_review: '付款待核对' }[value] || value) },
          { title: '状态', dataIndex: 'status', render: (value) => ({ pending: '等待发送 / 重试', processing: '发送中', sent: '已交给发送服务', failed: '失败，需人工重试', skipped: '通知未启用，已跳过' }[value] || value) },
          { title: '尝试次数', dataIndex: 'attempts' }, { title: '错误', dataIndex: 'last_error' },
          { title: '下次尝试', dataIndex: 'available_at', render: (value, record) => record.status === 'pending' ? fmt(value) : '-' },
        ]} />
      </ProCard>
      {order.card_replacements?.length > 0 && <ProCard title="售后换卡历史" style={{ marginTop: 16 }}>
        <Table rowKey="id" size="small" pagination={{ pageSize: 10 }} dataSource={order.card_replacements} scroll={{ x: 600 }} columns={[
          { title: '操作时间', dataIndex: 'created_at', render: fmt },
          { title: '处理人', render: (_, record) => record.admin?.username || '-' },
          { title: '原因', dataIndex: 'reason' },
          { title: '新旧卡编号', render: (_, record) => record.items?.map(item => `#${item.old_card_id} → #${item.new_card_id}`).join('；') },
        ]} />
      </ProCard>}
      {order.status === 'paid' && !order.cards_accessible && (
        <Alert type="info" showIcon message="当前账户没有卡密查看权限" description="店主可在管理员权限中单独授予卡密查看权限。" style={{ marginTop: 16 }} />
      )}
      {order.status === 'paid' && order.cards_accessible && order.cards && order.cards.length > 0 && (
        <ProCard title="当前交付卡密">
          {order.cards.map((card, idx) => (
            <Paragraph key={idx} copyable style={{ marginBottom: 4 }}>
              {card.content ?? ''}
            </Paragraph>
          ))}
        </ProCard>
      )}
    </div>
  );
}
