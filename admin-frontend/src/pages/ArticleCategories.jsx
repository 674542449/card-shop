import useWritePermission from '../hooks/useWritePermission';
import React, { useRef, useState } from 'react';
import { ProTable, ModalForm, ProFormText, ProFormDigit } from '@ant-design/pro-components';
import { Button, message, Popconfirm } from 'antd';
import { PlusOutlined } from '@ant-design/icons';
import { getArticleCategories, createArticleCategory, updateArticleCategory, deleteArticleCategory } from '../services/api';

export default function ArticleCategories() {
  const canWrite = useWritePermission('content');
  const actionRef = useRef();
  const [modalVisible, setModalVisible] = useState(false);
  const [editingRecord, setEditingRecord] = useState(null);

  const columns = [
    { title: 'ID', dataIndex: 'id', width: 60, search: false },
    { title: '名称', dataIndex: 'name' },
    { title: 'Slug', dataIndex: 'slug', search: false },
    { title: '排序', dataIndex: 'sort_order', search: false, width: 80 },
    { title: '文章数', dataIndex: 'articles_count', search: false, width: 90 },
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
              await deleteArticleCategory(record.id);
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
        form={{ name: 'article-categories-search' }}
        search={{ labelWidth: 'auto' }}
        request={async (params) => {
          const res = await getArticleCategories({ page: params.current, per_page: params.pageSize, ...params });
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
        name="article-category-editor"
        key={editingRecord?.id || 'new'}
        title={editingRecord ? '编辑分类' : '新增分类'}
        open={modalVisible}
        onOpenChange={setModalVisible}
        initialValues={editingRecord || { sort_order: 0 }}
        modalProps={{ destroyOnClose: true }}
        onFinish={async (values) => {
          try {
            const data = { ...values, sort_order: values.sort_order ?? 0 };
            if (editingRecord) {
              await updateArticleCategory(editingRecord.id, data);
              message.success('更新成功');
            } else {
              await createArticleCategory(data);
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
        <ProFormText name="slug" label="网址标识（Slug）" placeholder="留空自动生成" />
        <ProFormDigit name="sort_order" label="排序" min={0} />
      </ModalForm>
    </>
  );
}
