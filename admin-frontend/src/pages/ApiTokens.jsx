import useWritePermission from '../hooks/useWritePermission';
import React, { useRef, useState } from 'react';
import { ProTable, ModalForm, ProFormText, ProFormDigit, ProFormDateTimePicker, ProFormSelect, ProFormTextArea } from '@ant-design/pro-components';
import { Alert, Button, message, Modal, Popconfirm, Tag, Typography } from 'antd';
import { PlusOutlined } from '@ant-design/icons';
import dayjs from 'dayjs';
import { getApiTokens, createApiToken, updateApiToken, deleteApiToken } from '../services/api';

const formatDate = (value) => value ? dayjs(value).format('YYYY-MM-DD HH:mm:ss') : '尚未使用';

export default function ApiTokens() {
  const canWrite = useWritePermission('tokens');
  const actionRef = useRef();
  const [formVisible, setFormVisible] = useState(false);
  const [editing, setEditing] = useState(null);
  const [newSecret, setNewSecret] = useState('');

  const columns = [
    { title: 'ID', dataIndex: 'id', width: 60, search: false },
    {title:'有效期',dataIndex:'expires_at',search:false,render:(_,record)=>record.expires_at?dayjs(record.expires_at).format('YYYY-MM-DD HH:mm'):'长期有效'},
    // ProTable's first render argument is formatted display content, not the raw array.
    {title:'访问范围',dataIndex:'scopes',search:false,render:(_,record)=>record.scopes==null?'全部接口':(Array.isArray(record.scopes)?record.scopes.join('、'):'')||'无权限'},
    {title:'来源 IP',dataIndex:'allowed_ips',search:false,render:(_,record)=>(Array.isArray(record.allowed_ips)?record.allowed_ips.join('、'):'')||'不限'},
    { title: '名称', dataIndex: 'name' },
    { title: '请求/下单每分钟', search: false, width: 150, render: (_, record) => `${record.requests_per_minute} / ${record.orders_per_minute}` },
    { title: '待付额度（单/张）', search: false, width: 150, render: (_, record) => `${record.max_pending_orders} / ${record.max_pending_quantity}` },
    { title: '状态', dataIndex: 'is_active', width: 100, valueType: 'select', valueEnum: { 1: { text: '启用' }, 0: { text: '停用' } }, render: (_, record) => <Tag color={record.is_active ? 'green' : 'default'}>{record.is_active ? '启用' : '停用'}</Tag> },
    { title: '最近使用', dataIndex: 'last_used_at', search: false, width: 180, render: (_, record) => formatDate(record.last_used_at) },
    { title: '创建时间', dataIndex: 'created_at', search: false, valueType: 'dateTime', width: 180 },
    {
      title: '操作', valueType: 'option', width: 180, render: (_, record) => canWrite ? [
        <Button type="link" htmlType="button" style={{ padding: 0, height: 'auto' }} key="rename" onClick={() => { setEditing(record); setFormVisible(true); }}>编辑额度</Button>,
        <Popconfirm key="toggle" title={`确认${record.is_active ? '停用' : '启用'}此令牌？`} onConfirm={async () => {
          try {
            await updateApiToken(record.id, { is_active: !record.is_active });
            message.success(record.is_active ? '令牌已停用' : '令牌已启用');
            actionRef.current?.reload();
          } catch (err) { message.error(err.response?.data?.message || '更新失败'); }
        }}><Button type="link" htmlType="button" style={{ padding: 0, height: 'auto' }}>{record.is_active ? '停用' : '启用'}</Button></Popconfirm>,
        <Popconfirm key="delete" title="撤销此令牌？" description="使用此令牌的客户端会立即失去访问权限。" onConfirm={async () => {
          try { await deleteApiToken(record.id); message.success('令牌已撤销'); actionRef.current?.reload(); }
          catch (err) { message.error(err.response?.data?.message || '撤销失败'); }
        }}><Button type="link" htmlType="button" danger style={{ padding: 0, height: 'auto' }}>撤销</Button></Popconfirm>,
      ] : [],
    },
  ];

  return (
    <>
      <Alert type="info" showIcon style={{ marginBottom: 24 }} message="API 访问令牌" description="用于 /api/v1 的商品与订单接口。客户端通过 Authorization: Bearer <令牌> 访问；订单查询仍需要买家邮箱和查询密码。" />
      <ProTable
        actionRef={actionRef} rowKey="id" columns={columns} search={{ labelWidth: 'auto' }} form={{ name: 'api-tokens-search' }}
        request={async ({ current, pageSize, ...filters }) => {
          const res = await getApiTokens({ page: current, per_page: pageSize, ...filters });
          return { data: res.data.data, total: res.data.total, success: true };
        }}
        toolBarRender={() => canWrite ? [<Button key="create" type="primary" icon={<PlusOutlined />} onClick={() => { setEditing(null); setFormVisible(true); }}>创建令牌</Button>] : []}
      />
      <ModalForm name="api-token-editor"
        key={editing?.id || 'new'} title={editing ? '编辑 API 令牌' : '创建 API 令牌'} open={formVisible} onOpenChange={setFormVisible}
        initialValues={editing ? {...editing, allowed_ips_text:(editing.allowed_ips||[]).join('\n')} : { requests_per_minute: 120, orders_per_minute: 20, max_pending_orders: 3, max_pending_quantity: 100 }} modalProps={{ destroyOnClose: true }}
        onFinish={async (values) => {
          values = {...values, expires_at:values.expires_at || null, allowed_ips:(values.allowed_ips_text||'').split(/\n|,/).map(v=>v.trim()).filter(Boolean)};
          delete values.allowed_ips_text;
          try {
            if (editing) { await updateApiToken(editing.id, values); message.success('令牌配置已更新'); }
            else { const res = await createApiToken(values); setNewSecret(res.data.plain_token); }
            actionRef.current?.reload();
            return true;
          } catch (err) { message.error(err.response?.data?.message || '保存失败'); return false; }
        }}
      >
        <ProFormDateTimePicker name="expires_at" label="有效期（留空长期有效）" />
        <ProFormSelect name="scopes" label="访问范围" mode="multiple" initialValue={['products:read','orders:create','orders:query']} options={[{label:'查看商品',value:'products:read'},{label:'创建订单',value:'orders:create'},{label:'查询订单',value:'orders:query'},{label:'取消未付款订单',value:'orders:cancel'}]} />
        <ProFormTextArea name="allowed_ips_text" label="来源 IP / CIDR 白名单" extra="每行一个；留空不限制来源。" />
        <ProFormText name="name" label="名称" placeholder="例如：ERP 集成" rules={[{ required: true, message: '请填写名称' }, { max: 100, message: '名称最多 100 个字符' }]} />
        <ProFormDigit name="requests_per_minute" label="每分钟请求上限" min={1} max={6000} fieldProps={{ precision: 0 }} rules={[{ required: true }]} />
        <ProFormDigit name="orders_per_minute" label="每分钟下单上限" min={1} max={600} fieldProps={{ precision: 0 }} rules={[{ required: true }]} />
        <ProFormDigit name="max_pending_orders" label="同时待付款订单上限" min={1} max={1000} fieldProps={{ precision: 0 }} rules={[{ required: true }]} />
        <ProFormDigit name="max_pending_quantity" label="同时占用卡密上限" min={1} max={100000} fieldProps={{ precision: 0 }} rules={[{ required: true }]} />
      </ModalForm>
      <Modal title="保存你的新令牌" open={Boolean(newSecret)} onCancel={() => setNewSecret('')} onOk={() => setNewSecret('')} okText="已保存，关闭" cancelButtonProps={{ style: { display: 'none' } }} destroyOnClose>
        <Alert type="warning" showIcon message="令牌仅显示这一次，请复制后妥善保存。" style={{ marginBottom: 20 }} />
        <Typography.Paragraph copyable={{ text: newSecret }} style={{ overflowWrap: 'anywhere', fontFamily: 'monospace', padding: 16, background: 'var(--admin-bg, #faf9f5)', borderRadius: 10 }}>{newSecret}</Typography.Paragraph>
      </Modal>
    </>
  );
}
