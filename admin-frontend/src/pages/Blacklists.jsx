import useWritePermission from '../hooks/useWritePermission';
import React, { useRef, useState } from 'react';
import { ProTable, ModalForm, ProFormText, ProFormSelect, ProFormTextArea, ProFormDateTimePicker } from '@ant-design/pro-components';
import { Button, message, Popconfirm, Tag, Alert } from 'antd';
import dayjs from 'dayjs';
import { PlusOutlined } from '@ant-design/icons';
import { getBlacklists, createBlacklist, updateBlacklist, deleteBlacklist } from '../services/api';

export default function Blacklists() {
  const canWrite = useWritePermission('blacklists');
  const actionRef = useRef();
  const [modalVisible, setModalVisible] = useState(false);
  const [editingRecord, setEditingRecord] = useState(null);

  const columns = [
    { title: 'ID', dataIndex: 'id', width: 60, search: false },
    {
      title: '类型',
      dataIndex: 'type',
      width: 100,
      valueType: 'select',
      valueEnum: {
        ip: { text: 'IP' },
        email: { text: '邮箱' },
      },
      render: (_, record) =>
        record.type === 'ip' ? <Tag color="orange">IP</Tag> : <Tag color="blue">邮箱</Tag>,
    },
    { title: '值', dataIndex: 'value', copyable: true },
    { title: '原因', dataIndex: 'reason', search: false, ellipsis: true },
    {
      title: '来源', dataIndex: 'source', width: 110, valueType: 'select',
      valueEnum: { manual: { text: '手动添加' }, honeypot: { text: '扫描防护' } },
      render: (_, record) => record.source === 'honeypot' ? '扫描防护' : '手动添加',
    },
    {
      title: '状态', dataIndex: 'active', width: 90, valueType: 'select',
      valueEnum: { 1: { text: '生效中' }, 0: { text: '已过期' } },
      render: (_, record) => record.expires_at && dayjs(record.expires_at).isBefore(dayjs())
        ? <Tag>已过期</Tag> : <Tag color="orange">生效中</Tag>,
    },
    {
      title: '有效期', dataIndex: 'expires_at', search: false, width: 180,
      render: (_, record) => record.expires_at ? dayjs(record.expires_at).format('YYYY-MM-DD HH:mm') : '永久有效',
    },
    { title: '创建时间', dataIndex: 'created_at', valueType: 'dateTime', search: false, width: 180 },
    {
      title: '操作',
      valueType: 'option',
      width: 140,
      render: (_, record) => canWrite ? [
        <Button type="link" htmlType="button" style={{ padding: 0, height: 'auto' }} key="edit" onClick={() => { setEditingRecord(record); setModalVisible(true); }}>编辑</Button>,
        <Popconfirm
          key="delete"
          title="确认删除此黑名单记录？"
          onConfirm={async () => {
            try {
              await deleteBlacklist(record.id);
              message.success('删除成功');
              actionRef.current?.reload();
            } catch (err) {
              message.error(err.response?.data?.message || '删除失败');
            }
          }}
        >
          <Button type="link" htmlType="button" danger style={{ padding: 0, height: 'auto' }}>删除</Button>
        </Popconfirm>,
      ] : [],
    },
  ];

  return (
    <>
      <ProTable
        actionRef={actionRef}
        rowKey="id"
        columns={columns}
        form={{ name: 'blacklists-search' }}
        search={{ labelWidth: 'auto' }}
        request={async (params) => {
          const res = await getBlacklists({ page: params.current, per_page: params.pageSize, ...params });
          const body = res.data ?? {};
          const list = Array.isArray(body) ? body : (body.data ?? []);
          return {
            data: list,
            total: Array.isArray(body) ? list.length : (body.total ?? list.length),
            success: true,
          };
        }}
        toolBarRender={() => canWrite ? [
          <Button
            key="add"
            type="primary"
            icon={<PlusOutlined />}
            onClick={() => { setEditingRecord(null); setModalVisible(true); }}
          >
            新增黑名单
          </Button>,
        ] : []}
      />
      <ModalForm
        name="blacklist-editor"
        key={editingRecord?.id || 'new'}
        title={editingRecord ? '编辑黑名单' : '新增黑名单'}
        open={modalVisible}
        onOpenChange={setModalVisible}
        initialValues={editingRecord || { type: 'ip' }}
        modalProps={{ destroyOnClose: true }}
        onFinish={async (values) => {
          try {
            const data = { ...values, reason: values.reason ?? null, expires_at: values.expires_at ?? null };
            if (editingRecord) {
              await updateBlacklist(editingRecord.id, data);
            } else {
              await createBlacklist(data);
            }
            message.success(editingRecord ? '更新成功' : '创建成功');
            actionRef.current?.reload();
            return true;
          } catch (err) {
            message.error(err.response?.data?.message || '操作失败');
            return false;
          }
        }}
      >
        {editingRecord?.source === 'honeypot' && <Alert type="info" showIcon message="保存后转为手动规则，扫描防护不会覆盖此次调整。" style={{ marginBottom: 20 }} />}
        <ProFormSelect
          name="type"
          label="类型"
          options={[
            { label: 'IP', value: 'ip' },
            { label: '邮箱', value: 'email' },
          ]}
          rules={[{ required: true, message: '请选择类型' }]}
        />
        <ProFormText name="value" label="值" rules={[{ required: true, message: '请输入值' }]} placeholder="输入IP地址或邮箱" />
        <ProFormTextArea name="reason" label="原因" placeholder="可选，填写封禁原因" />
        <ProFormDateTimePicker name="expires_at" label="到期时间" placeholder="留空表示永久有效" />
      </ModalForm>
    </>
  );
}
