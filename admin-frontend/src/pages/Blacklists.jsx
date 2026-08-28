import React, { useRef, useState } from 'react';
import { ProTable, ModalForm, ProFormText, ProFormSelect, ProFormTextArea } from '@ant-design/pro-components';
import { Button, message, Popconfirm, Tag } from 'antd';
import { PlusOutlined } from '@ant-design/icons';
import { getBlacklists, createBlacklist, deleteBlacklist } from '../services/api';

export default function Blacklists() {
  const actionRef = useRef();
  const [modalVisible, setModalVisible] = useState(false);

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
    { title: '创建时间', dataIndex: 'created_at', valueType: 'dateTime', search: false, width: 180 },
    {
      title: '操作',
      valueType: 'option',
      width: 100,
      render: (_, record) => [
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
          <a style={{ color: '#ff4d4f' }}>删除</a>
        </Popconfirm>,
      ],
    },
  ];

  return (
    <>
      <ProTable
        headerTitle="黑名单管理"
        actionRef={actionRef}
        rowKey="id"
        columns={columns}
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
        toolBarRender={() => [
          <Button
            key="add"
            type="primary"
            icon={<PlusOutlined />}
            onClick={() => setModalVisible(true)}
          >
            新增黑名单
          </Button>,
        ]}
      />
      <ModalForm
        title="新增黑名单"
        open={modalVisible}
        onOpenChange={setModalVisible}
        modalProps={{ destroyOnClose: true }}
        onFinish={async (values) => {
          try {
            await createBlacklist(values);
            message.success('创建成功');
            actionRef.current?.reload();
            return true;
          } catch (err) {
            message.error(err.response?.data?.message || '操作失败');
            return false;
          }
        }}
      >
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
      </ModalForm>
    </>
  );
}
