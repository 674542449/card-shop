import React, { useRef, useState } from 'react';
import { ProTable, ModalForm, ProFormText, ProFormDigit, ProFormSwitch } from '@ant-design/pro-components';
import { Button, message, Popconfirm, Tag } from 'antd';
import { PlusOutlined } from '@ant-design/icons';
import { getCategories, createCategory, updateCategory, deleteCategory } from '../services/api';

export default function Categories() {
  const actionRef = useRef();
  const [modalVisible, setModalVisible] = useState(false);
  const [editingRecord, setEditingRecord] = useState(null);

  const columns = [
    { title: 'ID', dataIndex: 'id', width: 60, search: false },
    { title: '名称', dataIndex: 'name', copyable: true },
    { title: 'Slug', dataIndex: 'slug', search: false },
    { title: '排序', dataIndex: 'sort_order', search: false, width: 80 },
    {
      title: '状态',
      dataIndex: 'is_active',
      search: false,
      width: 80,
      render: (val) => (val ? <Tag color="green">启用</Tag> : <Tag color="red">禁用</Tag>),
    },
    {
      title: '操作',
      valueType: 'option',
      width: 150,
      render: (_, record) => [
        <a
          key="edit"
          onClick={() => {
            setEditingRecord(record);
            setModalVisible(true);
          }}
        >
          编辑
        </a>,
        <Popconfirm
          key="delete"
          title="确认删除此分类？"
          onConfirm={async () => {
            try {
              await deleteCategory(record.id);
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
        headerTitle="分类管理"
        actionRef={actionRef}
        rowKey="id"
        columns={columns}
        search={{ labelWidth: 'auto' }}
        request={async (params) => {
          const res = await getCategories({ page: params.current, per_page: params.pageSize, ...params });
          const d = res.data?.data || res.data;
          return {
            data: d.data || d,
            total: d.total || d.length,
            success: true,
          };
        }}
        toolBarRender={() => [
          <Button
            key="add"
            type="primary"
            icon={<PlusOutlined />}
            onClick={() => {
              setEditingRecord(null);
              setModalVisible(true);
            }}
          >
            新增分类
          </Button>,
        ]}
      />
      <ModalForm
        title={editingRecord ? '编辑分类' : '新增分类'}
        open={modalVisible}
        onOpenChange={setModalVisible}
        initialValues={editingRecord || { sort_order: 0, is_active: true }}
        modalProps={{ destroyOnClose: true }}
        onFinish={async (values) => {
          try {
            if (editingRecord) {
              await updateCategory(editingRecord.id, values);
              message.success('更新成功');
            } else {
              await createCategory(values);
              message.success('创建成功');
            }
            actionRef.current?.reload();
            return true;
          } catch (err) {
            message.error(err.response?.data?.message || '操作失败');
            return false;
          }
        }}
      >
        <ProFormText name="name" label="名称" rules={[{ required: true, message: '请输入名称' }]} />
        <ProFormText name="slug" label="Slug" rules={[{ required: true, message: '请输入 Slug' }]} />
        <ProFormDigit name="sort_order" label="排序" min={0} />
        <ProFormSwitch name="is_active" label="启用" />
      </ModalForm>
    </>
  );
}
