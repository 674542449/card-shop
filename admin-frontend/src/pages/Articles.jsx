import React, { useRef, useState, useEffect } from 'react';
import {
  ProTable,
  DrawerForm,
  ProFormText,
  ProFormTextArea,
  ProFormSwitch,
  ProFormSelect,
} from '@ant-design/pro-components';
import { Button, message, Popconfirm, Tag } from 'antd';
import { PlusOutlined } from '@ant-design/icons';
import { getArticles, createArticle, updateArticle, deleteArticle, getArticleCategories } from '../services/api';

export default function Articles() {
  const actionRef = useRef();
  const [drawerVisible, setDrawerVisible] = useState(false);
  const [editingRecord, setEditingRecord] = useState(null);
  const [categoryOptions, setCategoryOptions] = useState([]);

  useEffect(() => {
    getArticleCategories({ per_page: 100 })
      .then((res) => {
        const d = res.data?.data || res.data;
        const list = d.data || d;
        setCategoryOptions(list.map((c) => ({ label: c.name, value: c.id })));
      })
      .catch(() => {});
  }, []);

  const columns = [
    { title: 'ID', dataIndex: 'id', width: 60, search: false },
    { title: '标题', dataIndex: 'title' },
    {
      title: '分类',
      dataIndex: 'article_category_id',
      render: (_, record) => record.category?.name || '-',
      valueType: 'select',
      fieldProps: { options: categoryOptions },
    },
    {
      title: '发布状态',
      dataIndex: 'is_published',
      search: false,
      width: 100,
      render: (val) => (val ? <Tag color="green">已发布</Tag> : <Tag color="default">草稿</Tag>),
    },
    { title: '浏览量', dataIndex: 'views', search: false, width: 80 },
    { title: '创建时间', dataIndex: 'created_at', search: false, width: 180 },
    {
      title: '操作',
      valueType: 'option',
      width: 150,
      render: (_, record) => [
        <a
          key="edit"
          onClick={() => {
            setEditingRecord(record);
            setDrawerVisible(true);
          }}
        >
          编辑
        </a>,
        <Popconfirm
          key="delete"
          title="确认删除此文章？"
          onConfirm={async () => {
            try {
              await deleteArticle(record.id);
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
        headerTitle="文章管理"
        actionRef={actionRef}
        rowKey="id"
        columns={columns}
        search={{ labelWidth: 'auto' }}
        request={async (params) => {
          const res = await getArticles({ page: params.current, per_page: params.pageSize, ...params });
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
              setDrawerVisible(true);
            }}
          >
            新增文章
          </Button>,
        ]}
      />
      <DrawerForm
        title={editingRecord ? '编辑文章' : '新增文章'}
        open={drawerVisible}
        onOpenChange={setDrawerVisible}
        initialValues={editingRecord || { is_published: false }}
        drawerProps={{ destroyOnClose: true, width: 600 }}
        onFinish={async (values) => {
          try {
            if (editingRecord) {
              await updateArticle(editingRecord.id, values);
              message.success('更新成功');
            } else {
              await createArticle(values);
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
        <ProFormText name="title" label="标题" rules={[{ required: true, message: '请输入标题' }]} />
        <ProFormText name="slug" label="Slug" rules={[{ required: true, message: '请输入 Slug' }]} />
        <ProFormSelect name="article_category_id" label="分类" options={categoryOptions} />
        <ProFormTextArea name="summary" label="摘要" fieldProps={{ rows: 3 }} />
        <ProFormTextArea name="content" label="内容" rules={[{ required: true, message: '请输入内容' }]} fieldProps={{ rows: 12 }} />
        <ProFormText name="cover_image" label="封面图片" placeholder="输入图片URL" />
        <ProFormSwitch name="is_published" label="发布" />
        <ProFormText name="seo_title" label="SEO 标题" />
        <ProFormTextArea name="seo_description" label="SEO 描述" />
        <ProFormText name="seo_keywords" label="SEO 关键词" />
      </DrawerForm>
    </>
  );
}
