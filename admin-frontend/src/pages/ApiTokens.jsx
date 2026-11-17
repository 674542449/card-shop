import useWritePermission from '../hooks/useWritePermission';
import React, { useRef, useState } from 'react';
import {
  ProTable,
  ModalForm,
  ProFormText,
  ProFormDigit,
  ProFormDateTimePicker,
  ProFormSelect,
  ProFormTextArea,
} from '@ant-design/pro-components';
import { Alert, Button, message, Modal, Popconfirm, Tag, Typography } from 'antd';
import { PlusOutlined } from '@ant-design/icons';
import { getApiTokens, createApiToken, updateApiToken, deleteApiToken } from '../services/api';
import { formatDateTime, formatDateMinute } from '../utils/orderMeta';

export default function ApiTokens() {
  const canWrite = useWritePermission('tokens');
  const actionRef = useRef();
  const [formVisible, setFormVisible] = useState(false);
  const [editing, setEditing] = useState(null);
  const [newSecret, setNewSecret] = useState('');

  const columns = [
    { title: 'ID', dataIndex: 'id', width: 64, search: false },
    { title: '令牌名称', dataIndex: 'name', width: 160 },
    {
      title: '授权范围',
      dataIndex: 'scopes',
      search: false,
      width: 220,
      render: (_, record) =>
        record.scopes == null
          ? '全部接口权限'
          : (Array.isArray(record.scopes) ? record.scopes.join('、') : '') || '无权限',
    },
    {
      title: '来源 IP 白名单',
      dataIndex: 'allowed_ips',
      search: false,
      width: 160,
      render: (_, record) => (Array.isArray(record.allowed_ips) ? record.allowed_ips.join('、') : '') || '不限来源',
    },
    {
      title: '速率上限（请求 / 下单每分钟）',
      search: false,
      width: 185,
      align: 'right',
      render: (_, record) => `${record.requests_per_minute} / ${record.orders_per_minute}`,
    },
    {
      title: '并发待付配额（单 / 件）',
      search: false,
      width: 165,
      align: 'right',
      render: (_, record) => `${record.max_pending_orders} / ${record.max_pending_quantity}`,
    },
    {
      title: '状态',
      dataIndex: 'is_active',
      width: 90,
      valueType: 'select',
      valueEnum: { 1: { text: '启用' }, 0: { text: '停用' } },
      render: (_, record) => (
        <Tag color={record.is_active ? 'green' : 'default'}>{record.is_active ? '启用' : '停用'}</Tag>
      ),
    },
    {
      title: '有效期至',
      dataIndex: 'expires_at',
      search: false,
      width: 155,
      render: (_, record) => (record.expires_at ? formatDateMinute(record.expires_at) : '长期有效'),
    },
    {
      title: '最近调用时间',
      dataIndex: 'last_used_at',
      search: false,
      width: 175,
      render: (_, record) => (record.last_used_at ? formatDateTime(record.last_used_at) : '尚未使用'),
    },
    { title: '创建时间', dataIndex: 'created_at', search: false, valueType: 'dateTime', width: 175 },
    {
      title: '操作',
      valueType: 'option',
      width: 175,
      fixed: 'right',
      render: (_, record) =>
        canWrite
          ? [
              <Button
                type="link"
                htmlType="button"
                style={{ padding: 0, height: 'auto' }}
                key="rename"
                onClick={() => {
                  setEditing(record);
                  setFormVisible(true);
                }}
              >
                配置额度
              </Button>,
              <Popconfirm
                key="toggle"
                title={`确认${record.is_active ? '停用' : '启用'}该 API 令牌？`}
                onConfirm={async () => {
                  try {
                    await updateApiToken(record.id, { is_active: !record.is_active });
                    message.success(record.is_active ? '令牌已停用' : '令牌已启用');
                    actionRef.current?.reload();
                  } catch (err) {
                    message.error(err.response?.data?.message || '状态更新失败');
                  }
                }}
              >
                <Button type="link" htmlType="button" style={{ padding: 0, height: 'auto' }}>
                  {record.is_active ? '停用' : '启用'}
                </Button>
              </Popconfirm>,
              <Popconfirm
                key="delete"
                title="确认永久撤销该令牌？"
                description="撤销后使用该凭据的外部集成客户端将立即失去调用权限。"
                onConfirm={async () => {
                  try {
                    await deleteApiToken(record.id);
                    message.success('令牌已撤销');
                    actionRef.current?.reload();
                  } catch (err) {
                    message.error(err.response?.data?.message || '撤销失败');
                  }
                }}
              >
                <Button type="link" htmlType="button" danger style={{ padding: 0, height: 'auto' }}>
                  撤销
                </Button>
              </Popconfirm>,
            ]
          : [],
    },
  ];

  return (
    <>
      <Alert
        type="info"
        showIcon
        style={{ marginBottom: 16 }}
        message="开放接口（/api/v1）访问凭据管理"
        description="第三方系统可通过请求头 Authorization: Bearer <Token> 调用商品与订单接口；查询订单卡密仍须校验买家的下单邮箱与查询密码。"
      />
      <ProTable
        actionRef={actionRef}
        rowKey="id"
        columns={columns}
        scroll={{ x: 1500 }}
        search={{ labelWidth: 'auto' }}
        form={{ name: 'api-tokens-search' }}
        request={async ({ current, pageSize, ...filters }) => {
          const res = await getApiTokens({ page: current, per_page: pageSize, ...filters });
          return { data: res.data.data, total: res.data.total, success: true };
        }}
        toolBarRender={() =>
          canWrite
            ? [
                <Button
                  key="create"
                  type="primary"
                  icon={<PlusOutlined />}
                  onClick={() => {
                    setEditing(null);
                    setFormVisible(true);
                  }}
                >
                  创建令牌
                </Button>,
              ]
            : []
        }
      />
      <ModalForm
        name="api-token-editor"
        key={editing?.id || 'new'}
        title={editing ? '编辑 API 令牌配额' : '签发新 API 令牌'}
        open={formVisible}
        onOpenChange={setFormVisible}
        initialValues={
          editing
            ? { ...editing, allowed_ips_text: (editing.allowed_ips || []).join('\n') }
            : {
                requests_per_minute: 120,
                orders_per_minute: 20,
                max_pending_orders: 3,
                max_pending_quantity: 100,
              }
        }
        modalProps={{ destroyOnHidden: true }}
        onFinish={async (values) => {
          values = {
            ...values,
            expires_at: values.expires_at || null,
            allowed_ips: (values.allowed_ips_text || '')
              .split(/\n|,/)
              .map((v) => v.trim())
              .filter(Boolean),
          };
          delete values.allowed_ips_text;
          try {
            if (editing) {
              await updateApiToken(editing.id, values);
              message.success('令牌配额已更新');
            } else {
              const res = await createApiToken(values);
              setNewSecret(res.data.plain_token);
            }
            actionRef.current?.reload();
            return true;
          } catch (err) {
            message.error(err.response?.data?.message || '保存失败');
            return false;
          }
        }}
      >
        <div className="admin-form-grid-2">
          <ProFormText
            name="name"
            label="客户端标识名称"
            placeholder="例如：ERP 订单同步系统"
            rules={[
              { required: true, message: '请输入客户端标识名称' },
              { max: 100, message: '名称长度不得超过 100 个字符' },
            ]}
          />
          <ProFormDateTimePicker name="expires_at" label="过期失效时间（留空则长期有效）" />
        </div>
        <ProFormSelect
          name="scopes"
          label="接口权限范围"
          mode="multiple"
          initialValue={['products:read', 'orders:create', 'orders:query']}
          options={[
            { label: '读取商品列表与详情（products:read）', value: 'products:read' },
            { label: '创建交易订单（orders:create）', value: 'orders:create' },
            { label: '查询订单与交付结果（orders:query）', value: 'orders:query' },
            { label: '取消未付款订单（orders:cancel）', value: 'orders:cancel' },
          ]}
        />
        <div className="admin-form-grid-2">
          <ProFormDigit
            name="requests_per_minute"
            label="每分钟接口总请求上限"
            min={1}
            max={6000}
            fieldProps={{ precision: 0 }}
            rules={[{ required: true }]}
          />
          <ProFormDigit
            name="orders_per_minute"
            label="每分钟创建订单上限"
            min={1}
            max={600}
            fieldProps={{ precision: 0 }}
            rules={[{ required: true }]}
          />
        </div>
        <div className="admin-form-grid-2">
          <ProFormDigit
            name="max_pending_orders"
            label="并发待支付订单上限"
            min={1}
            max={1000}
            fieldProps={{ precision: 0 }}
            rules={[{ required: true }]}
          />
          <ProFormDigit
            name="max_pending_quantity"
            label="并发锁定卡密数量上限"
            min={1}
            max={100000}
            fieldProps={{ precision: 0 }}
            rules={[{ required: true }]}
          />
        </div>
        <ProFormTextArea
          name="allowed_ips_text"
          label="允许访问的来源 IP / CIDR 白名单"
          fieldProps={{ rows: 3 }}
          extra="每行填写一个 IP 或 CIDR 网段；留空表示不限制调用来源 IP。"
        />
      </ModalForm>
      <Modal
        title="请立即保存新签发的 API 令牌密钥"
        open={Boolean(newSecret)}
        onCancel={() => setNewSecret('')}
        onOk={() => setNewSecret('')}
        okText="已安全保存，关闭窗口"
        cancelButtonProps={{ style: { display: 'none' } }}
        destroyOnHidden
      >
        <Alert
          type="warning"
          showIcon
          message="数据库仅存储该令牌的 SHA-256 哈希摘要，明文密钥仅在此处展示一次，关闭后无法再次查看。"
          style={{ marginBottom: 16 }}
        />
        <div className="admin-secret-item">
          <Typography.Paragraph copyable={{ text: newSecret }} style={{ overflowWrap: 'anywhere', marginBottom: 0 }}>
            {newSecret}
          </Typography.Paragraph>
        </div>
      </Modal>
    </>
  );
}
