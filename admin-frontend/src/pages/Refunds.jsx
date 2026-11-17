import React, { useRef, useState } from 'react';
import { ProTable, ModalForm, ProFormSelect, ProFormText, ProFormTextArea } from '@ant-design/pro-components';
import { Alert, Button, Tag, message } from 'antd';
import api from '../services/api';
import { useOutletContext, useSearchParams } from 'react-router-dom';
import Link from '../components/PermissionLink';
import { allows } from '../permissions';
import { REFUND_STATUS_MAP } from '../utils/orderMeta';

const STATUS_ENUM = Object.fromEntries(
  Object.entries(REFUND_STATUS_MAP).map(([key, val]) => [key, { text: val.text }])
);

export default function Refunds() {
  const admin = useOutletContext();
  const write = allows(admin, 'refunds', 'write');
  const [params] = useSearchParams();
  const initialStatus = REFUND_STATUS_MAP[params.get('status')] ? params.get('status') : undefined;
  const ref = useRef();
  const [editing, setEditing] = useState(null);

  return (
    <>
      <Alert
        type="info"
        showIcon
        message="退款审核与打款凭证管理"
        description="批准退款申请后，请在原支付渠道完成实际打款，并在此回填退款流水凭证编号。已完成交付的卡密不会重新退回可售库存。"
        style={{ marginBottom: 16 }}
      />
      <ProTable
        key={initialStatus || 'all'}
        scroll={{ x: 'max-content' }}
        form={{ name: 'refunds-search', initialValues: { status: initialStatus } }}
        actionRef={ref}
        rowKey="id"
        request={async (p) => {
          const r = await api.get('/refunds', {
            params: { page: p.current, per_page: p.pageSize, status: p.status },
          });
          return { data: r.data.data, total: r.data.total, success: true };
        }}
        columns={[
          { title: '工单 ID', dataIndex: 'id', search: false, width: 80 },
          {
            title: '关联订单',
            width: 215,
            search: false,
            render: (_, r) =>
              r.order ? (
                <Link className="admin-order-number" to={`/orders/${r.order.id}`}>
                  {r.order.order_no}
                </Link>
              ) : (
                '-'
              ),
          },
          {
            title: '退款金额',
            dataIndex: 'amount',
            search: false,
            width: 120,
            align: 'right',
            render: (v) => <span className="admin-amount">¥{v}</span>,
          },
          {
            title: '处理状态',
            dataIndex: 'status',
            width: 145,
            valueType: 'select',
            valueEnum: STATUS_ENUM,
            render: (_, r) => {
              const s = REFUND_STATUS_MAP[r.status];
              return s ? <Tag color={s.color}>{s.text}</Tag> : r.status;
            },
          },
          { title: '申请原因', dataIndex: 'reason', search: false, width: 220, ellipsis: true },
          {
            title: '退款流水凭证',
            dataIndex: 'reference',
            search: false,
            width: 180,
            render: (v) => (v ? <span className="admin-mono">{v}</span> : '-'),
          },
          { title: '内部备注', dataIndex: 'note', search: false, width: 180, ellipsis: true },
          { title: '买家可见说明', dataIndex: 'customer_note', search: false, width: 200, ellipsis: true },
          {
            title: '操作',
            search: false,
            width: 90,
            fixed: 'right',
            render: (_, r) =>
              write && ['requested', 'approved'].includes(r.status) ? (
                <Button size="small" type="primary" ghost onClick={() => setEditing(r)}>
                  处理
                </Button>
              ) : null,
          },
        ]}
      />
      <ModalForm
        name="refund-review"
        key={editing?.id}
        title="处理退款工单"
        initialValues={{
          reference: editing?.reference,
          note: editing?.note,
          customer_note: editing?.customer_note,
        }}
        open={!!editing}
        onOpenChange={(v) => !v && setEditing(null)}
        onFinish={async (v) => {
          try {
            await api.put(`/refunds/${editing.id}`, v);
            message.success('退款工单处理结果已保存');
            ref.current?.reload();
            return true;
          } catch (e) {
            message.error(e.response?.data?.message || '处理失败');
            return false;
          }
        }}
      >
        <ProFormSelect
          name="status"
          label="处理结论"
          rules={[{ required: true, message: '请选择处理结论' }]}
          options={(editing?.status === 'requested' ? ['approved', 'rejected'] : ['completed', 'rejected']).map(
            (v) => ({ value: v, label: REFUND_STATUS_MAP[v]?.text || v })
          )}
        />
        <ProFormText name="reference" label="原渠道退款流水 / 凭证编号（标记为已退款时必填）" />
        <ProFormTextArea name="note" label="内部处理备注（仅后台可见）" fieldProps={{ maxLength: 2000, rows: 3 }} />
        <ProFormTextArea
          name="customer_note"
          label="买家可见处理说明"
          fieldProps={{ maxLength: 2000, rows: 3 }}
          extra="该说明将展示给买家，请勿填写内部敏感信息或网关密钥。"
        />
      </ModalForm>
    </>
  );
}
