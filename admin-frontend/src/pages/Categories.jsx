import useWritePermission from '../hooks/useWritePermission';
import React, { useRef, useState } from 'react';
import {
  ProTable,
  ModalForm,
  ProForm,
  ProFormText,
  ProFormTextArea,
  ProFormDigit,
  ProFormSwitch,
} from '@ant-design/pro-components';
import { Button, message, Popconfirm, Tag, Image } from 'antd';
import { PlusOutlined } from '@ant-design/icons';
import { getCategories, createCategory, updateCategory, deleteCategory } from '../services/api';
import ImageUploader from '../components/ImageUploader';

export default function Categories() {
  const canWrite = useWritePermission('catalog');
  const actionRef = useRef();
  const [modalVisible, setModalVisible] = useState(false);
  const [editingRecord, setEditingRecord] = useState(null);

  const columns = [
    { title: 'ID', dataIndex: 'id', width: 60, search: false },
    {
      title: '缩略图',
      dataIndex: 'image',
      search: false,
      width: 90,
      render: (_, record) =>
        record.image ? (
          <Image src={record.image} width={40} height={40} style={{ objectFit: 'cover', borderRadius: 4 }} />
        ) : (
          '-'
        ),
    },
    { title: '名称', dataIndex: 'name', copyable: true },
    { title: 'Slug', dataIndex: 'slug', search: false },
    { title: '排序', dataIndex: 'sort_order', search: false, width: 80 },
    { title: '商品数', dataIndex: 'products_count', search: false, width: 90 },
    {
      title: '状态',
      dataIndex: 'is_active',
      valueType: 'select',
      valueEnum: { 1: { text: '启用' }, 0: { text: '禁用' } },
      width: 80,
      render: (_, record) =>
        record.is_active ? <Tag color="green">启用</Tag> : <Tag color="red">禁用</Tag>,
    },
    {
      title: '操作',
      valueType: 'option',
      width: 150,
      render: (_, record) => canWrite ? [
        <Button type="link" htmlType="button" style={{ padding: 0, height: 'auto' }}
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
        form={{ name: 'categories-search' }}
        search={{ labelWidth: 'auto' }}
        request={async (params) => {
          const res = await getCategories({ page: params.current, per_page: params.pageSize, ...params });
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
            onClick={() => {
              setEditingRecord(null);
              setModalVisible(true);
            }}
          >
            新增分类
          </Button>,
        ] : []}
      />
      <ModalForm
        name="category-editor"
        key={editingRecord?.id || 'new'}
        title={editingRecord ? '编辑分类' : '新增分类'}
        open={modalVisible}
        onOpenChange={setModalVisible}
        initialValues={editingRecord || { sort_order: 0, is_active: true }}
        modalProps={{ destroyOnClose: true }}
        onFinish={async (values) => {
          try {
            // ProForm's omitNil strips null values out of `values` entirely, so clearing
            // the thumbnail would submit no `image` key at all and the old URL would
            // survive the save. Put it back explicitly.
            const payload = { ...values, image: values.image ?? null, description: values.description ?? null, sort_order: values.sort_order ?? 0 };

            if (editingRecord) {
              await updateCategory(editingRecord.id, payload);
              message.success('更新成功');
            } else {
              await createCategory(payload);
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
        <ProFormText
          name="slug"
          label="Slug"
          placeholder="留空自动生成，或填写 digital-goods"
          extra="建议使用英文、数字和连字符，便于分享分类页网址。"
        />
        <ProFormTextArea
          name="description"
          label="分类描述"
          fieldProps={{ rows: 3 }}
          extra="显示在该分类的商品列表页顶部。"
        />
        <ProForm.Item name="image" label="分类缩略图">
          <ImageUploader />
        </ProForm.Item>
        <ProFormDigit name="sort_order" label="排序" min={0} />
        <ProFormSwitch name="is_active" label="启用" />
      </ModalForm>
    </>
  );
}
