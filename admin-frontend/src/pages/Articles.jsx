import useWritePermission from '../hooks/useWritePermission';
import React, { useRef, useState, useEffect } from 'react';
import {
  ProTable,
  DrawerForm,
  ProForm,
  ProFormText,
  ProFormTextArea,
  ProFormSwitch,
  ProFormSelect,
} from '@ant-design/pro-components';
import { Button, message, Popconfirm, Tag, Image } from 'antd';
import { PlusOutlined } from '@ant-design/icons';
import { getArticles, createArticle, updateArticle, deleteArticle, getArticleCategories } from '../services/api';
import ImageUploader from '../components/ImageUploader';
import RichTextEditor from '../components/RichTextEditor';
import ProductEmbedPicker from '../components/ProductEmbedPicker';

export default function Articles() {
  const canWrite = useWritePermission('content');
  const actionRef = useRef();
  const articleFormRef = useRef();
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
    {
      title: '封面',
      dataIndex: 'cover_image',
      search: false,
      width: 80,
      render: (_, record) =>
        record.cover_image ? (
          <Image src={record.cover_image} width={40} height={40} style={{ objectFit: 'cover', borderRadius: 4 }} />
        ) : (
          '-'
        ),
    },
    { title: '标题', dataIndex: 'title' },
    {
      title: '分类',
      dataIndex: 'article_category_id',
      // The API eager-loads articleCategory, which serialises as article_category.
      render: (_, record) => record.article_category?.name || '-',
      valueType: 'select',
      fieldProps: { options: categoryOptions },
    },
    {
      title: '发布状态',
      dataIndex: 'is_published',
      valueType: 'select',
      valueEnum: { 1: { text: '已发布' }, 0: { text: '草稿' } },
      width: 100,
      render: (_, record) =>
        record.is_published ? <Tag color="green">已发布</Tag> : <Tag color="default">草稿</Tag>,
    },
    { title: '浏览量', dataIndex: 'views', search: false, width: 80 },
    { title: '创建时间', dataIndex: 'created_at', valueType: 'dateTime', search: false, width: 180 },
    {
      title: '操作',
      valueType: 'option',
      width: 150,
      render: (_, record) => canWrite ? [
        <Button type="link" htmlType="button" style={{ padding: 0, height: 'auto' }}
          key="edit"
          onClick={() => {
            setEditingRecord(record);
            setDrawerVisible(true);
          }}
        >
          编辑
        </Button>,
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
        form={{ name: 'articles-search' }}
        search={{ labelWidth: 'auto' }}
        request={async (params) => {
          const res = await getArticles({ page: params.current, per_page: params.pageSize, ...params });
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
              setDrawerVisible(true);
            }}
          >
            新增文章
          </Button>,
        ] : []}
      />
      <DrawerForm
        name="article-editor"
        formRef={articleFormRef}
        key={editingRecord?.id || 'new'}
        title={editingRecord ? '编辑文章' : '新增文章'}
        open={drawerVisible}
        onOpenChange={setDrawerVisible}
        initialValues={editingRecord || { is_published: false }}
        drawerProps={{ destroyOnClose: true, width: 800 }}
        onFinish={async (values) => {
          try {
            // omitNil would drop a cleared cover image from the payload, leaving the
            // old one in place while the toast reports success.
            const payload = {
              ...values,
              cover_image: values.cover_image ?? null,
              summary: values.summary ?? null,
              seo_title: values.seo_title ?? null,
              seo_description: values.seo_description ?? null,
              seo_keywords: values.seo_keywords ?? null,
            };

            if (editingRecord) {
              await updateArticle(editingRecord.id, payload);
              message.success('更新成功');
            } else {
              await createArticle(payload);
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
        <ProFormText name="slug" label="网址标识（Slug）" placeholder="留空自动生成" />
        <ProFormSelect
          name="article_category_id"
          label="分类"
          options={categoryOptions}
          rules={[{ required: true, message: '请选择分类' }]}
        />
        <ProFormTextArea name="summary" label="摘要" fieldProps={{ rows: 3 }} />
        <ProductEmbedPicker formRef={articleFormRef} />
        <ProForm.Item name="content" label="内容" rules={[{ required: true, message: '请输入内容' }]}>
          <RichTextEditor placeholder="请输入文章内容" height={360} />
        </ProForm.Item>
        <ProForm.Item name="cover_image" label="封面图片">
          <ImageUploader />
        </ProForm.Item>
        <ProFormSwitch name="is_published" label="发布" />
        <ProFormText name="seo_title" label="SEO 标题" />
        <ProFormTextArea name="seo_description" label="SEO 描述" />
        <ProFormText name="seo_keywords" label="SEO 关键词" />
      </DrawerForm>
    </>
  );
}
