import useWritePermission from '../hooks/useWritePermission';
import React, { useRef, useState } from 'react';
import { Link, useOutletContext } from 'react-router-dom';
import {
  ProTable,
  ModalForm,
  ProFormText,
  ProFormSelect,
  ProFormTextArea,
  ProFormDateTimePicker,
} from '@ant-design/pro-components';
import { Button, message, Popconfirm, Tag, Alert } from 'antd';
import dayjs from 'dayjs';
import { PlusOutlined, SafetyCertificateOutlined } from '@ant-design/icons';
import { getBlacklists, createBlacklist, updateBlacklist, deleteBlacklist } from '../services/api';
import { formatDateMinute, toTablePage } from '../utils/orderMeta';
import { canVisit } from '../permissions';

export default function Blacklists() {
  const admin = useOutletContext();
  const canWrite = useWritePermission('blacklists');
  const canOpenSettings = canVisit(admin, '/settings');
  const actionRef = useRef();
  const [modalVisible, setModalVisible] = useState(false);
  const [editingRecord, setEditingRecord] = useState(null);

  const columns = [
    { title: 'ID', dataIndex: 'id', width: 64, search: false },
    {
      title: '拦截类型',
      dataIndex: 'type',
      width: 100,
      valueType: 'select',
      valueEnum: {
        ip: { text: 'IP 地址' },
        email: { text: '电子邮箱' },
      },
      render: (_, record) =>
        record.type === 'ip' ? <Tag color="orange">IP 地址</Tag> : <Tag color="blue">电子邮箱</Tag>,
    },
    {
      title: '封禁目标值',
      dataIndex: 'value',
      copyable: true,
      render: (_, record) => <span className="admin-mono">{record.value}</span>,
    },
    { title: '封禁原因', dataIndex: 'reason', search: false, ellipsis: true },
    {
      title: '规则来源',
      dataIndex: 'source',
      width: 115,
      valueType: 'select',
      valueEnum: { manual: { text: '人工配置' }, honeypot: { text: '扫描防护' } },
      render: (_, record) => (record.source === 'honeypot' ? '扫描防护' : '人工配置'),
    },
    {
      title: '当前状态',
      dataIndex: 'active',
      width: 100,
      valueType: 'select',
      valueEnum: { 1: { text: '生效中' }, 0: { text: '已到期' } },
      render: (_, record) =>
        record.expires_at && dayjs(record.expires_at).isBefore(dayjs()) ? (
          <Tag>已到期</Tag>
        ) : (
          <Tag color="red">生效中</Tag>
        ),
    },
    {
      title: '截止有效期',
      dataIndex: 'expires_at',
      search: false,
      width: 165,
      render: (_, record) => (record.expires_at ? formatDateMinute(record.expires_at) : '永久封禁'),
    },
    { title: '创建时间', dataIndex: 'created_at', valueType: 'dateTime', search: false, width: 175 },
    {
      title: '操作',
      valueType: 'option',
      width: 130,
      render: (_, record) =>
        canWrite
          ? [
              <Button
                type="link"
                htmlType="button"
                style={{ padding: 0, height: 'auto' }}
                key="edit"
                onClick={() => {
                  setEditingRecord(record);
                  setModalVisible(true);
                }}
              >
                编辑
              </Button>,
              <Popconfirm
                key="delete"
                title="确认解除并删除此封禁记录？"
                onConfirm={async () => {
                  try {
                    await deleteBlacklist(record.id);
                    message.success('封禁记录已解除');
                    actionRef.current?.reload();
                  } catch (err) {
                    message.error(err.response?.data?.message || '删除失败');
                  }
                }}
              >
                <Button type="link" htmlType="button" danger style={{ padding: 0, height: 'auto' }}>
                  删除
                </Button>
              </Popconfirm>,
            ]
          : [],
    },
  ];

  return (
    <>
      <ProTable
        actionRef={actionRef}
        rowKey="id"
        columns={columns}
        scroll={{ x: 'max-content' }}
        form={{ name: 'blacklists-search' }}
        search={{ labelWidth: 'auto' }}
        request={async (params) => {
          const res = await getBlacklists({ page: params.current, per_page: params.pageSize, ...params });
          return toTablePage(res);
        }}
        toolBarRender={() => [
          canOpenSettings && (
            <Link
              key="honeypot-whitelist"
              to="/settings?tab=security"
              className="ant-btn ant-btn-default"
              style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}
            >
              <SafetyCertificateOutlined />
              <span>蜜罐 IP 白名单</span>
            </Link>
          ),
          canWrite && (
            <Button
              key="add"
              type="primary"
              icon={<PlusOutlined />}
              onClick={() => {
                setEditingRecord(null);
                setModalVisible(true);
              }}
            >
              新增封禁规则
            </Button>
          ),
        ].filter(Boolean)}
      />
      <ModalForm
        name="blacklist-editor"
        key={editingRecord?.id || 'new'}
        title={editingRecord ? '编辑封禁规则' : '新增封禁规则'}
        open={modalVisible}
        onOpenChange={setModalVisible}
        initialValues={editingRecord || { type: 'ip' }}
        modalProps={{ destroyOnHidden: true }}
        onFinish={async (values) => {
          try {
            const data = {
              ...values,
              reason: values.reason ?? null,
              expires_at: values.expires_at ?? null,
            };
            if (editingRecord) {
              await updateBlacklist(editingRecord.id, data);
            } else {
              await createBlacklist(data);
            }
            message.success(editingRecord ? '封禁规则已更新' : '封禁规则已生效');
            actionRef.current?.reload();
            return true;
          } catch (err) {
            message.error(err.response?.data?.message || '保存失败');
            return false;
          }
        }}
      >
        {editingRecord?.source === 'honeypot' && (
          <Alert
            type="info"
            showIcon
            message="保存修改后该记录将转为人工配置规则，后续自动扫描防护触发时不会覆盖本次调整。"
            style={{ marginBottom: 18 }}
          />
        )}
        <div className="admin-form-grid-2">
          <ProFormSelect
            name="type"
            label="封禁维度"
            options={[
              { label: 'IP 地址（IPv4 / IPv6）', value: 'ip' },
              { label: '联系邮箱地址', value: 'email' },
            ]}
            rules={[{ required: true, message: '请选择封禁维度' }]}
          />
          <ProFormDateTimePicker name="expires_at" label="到期解封时间" placeholder="留空表示永久封禁" />
        </div>
        <ProFormText
          name="value"
          label="目标 IP 或邮箱"
          rules={[{ required: true, message: '请输入目标 IP 或邮箱地址' }]}
          placeholder="请输入标准 IPv4 / IPv6 地址或完整电子邮箱"
        />
        <ProFormTextArea name="reason" label="封禁原因备注" fieldProps={{ rows: 3 }} placeholder="选填，记录风控拦截原因以便审计核查" />
      </ModalForm>
    </>
  );
}
