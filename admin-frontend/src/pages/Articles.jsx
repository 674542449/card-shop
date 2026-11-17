import useWritePermission from '../hooks/useWritePermission';
import React, { useRef, useState, useEffect, useCallback } from 'react';
import {
  ProTable,
  DrawerForm,
  ModalForm,
  ProForm,
  ProFormText,
  ProFormTextArea,
  ProFormDigit,
  ProFormSwitch,
  ProFormSelect,
} from '@ant-design/pro-components';
import { Button, message, Popconfirm, Tag, Image, Tabs } from 'antd';
import { PlusOutlined } from '@ant-design/icons';
import { useSearchParams } from 'react-router-dom';
import {
  getArticles,
  getArticle,
  createArticle,
  updateArticle,
  deleteArticle,
  getArticleCategories,
  createArticleCategory,
  updateArticleCategory,
  deleteArticleCategory,
} from '../services/api';
import ImageUploader from '../components/ImageUploader';
import RichTextEditor from '../components/RichTextEditor';
import ProductEmbedPicker from '../components/ProductEmbedPicker';
import { toTablePage } from '../utils/orderMeta';

function ArticleCategoriesPanel({ canWrite, onChanged }) {
  const actionRef = useRef();
  const [modalVisible, setModalVisible] = useState(false);
  const [editingRecord, setEditingRecord] = useState(null);

  const columns = [
    { title: 'ID', dataIndex: 'id', width: 64, search: false },
    { title: '分类名称', dataIndex: 'name' },
    { title: '网址标识（Slug）', dataIndex: 'slug', search: false },
    { title: '排序权重', dataIndex: 'sort_order', search: false, width: 95 },
    { title: '关联文章数', dataIndex: 'articles_count', search: false, width: 105 },
    {
      title: '操作',
      valueType: 'option',
      width: 140,
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
                title="确认删除该文章分类？"
                onConfirm={async () => {
                  try {
                    await deleteArticleCategory(record.id);
                    message.success('文章分类已删除');
                    actionRef.current?.reload();
                    onChanged?.();
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
        form={{ name: 'article-categories-search' }}
        search={{ labelWidth: 'auto' }}
        request={async (params) => {
          const res = await getArticleCategories({ page: params.current, per_page: params.pageSize, ...params });
          return toTablePage(res);
        }}
        toolBarRender={() =>
          canWrite
            ? [
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
              ]
            : []
        }
      />
      <ModalForm
        name="article-category-editor"
        key={editingRecord?.id || 'new'}
        title={editingRecord ? '编辑文章分类' : '新增文章分类'}
        open={modalVisible}
        onOpenChange={setModalVisible}
        initialValues={editingRecord || { sort_order: 0 }}
        modalProps={{ destroyOnHidden: true }}
        onFinish={async (values) => {
          try {
            const data = { ...values, sort_order: values.sort_order ?? 0 };
            if (editingRecord) {
              await updateArticleCategory(editingRecord.id, data);
              message.success('分类已更新');
            } else {
              await createArticleCategory(data);
              message.success('分类已创建');
            }
            actionRef.current?.reload();
            onChanged?.();
            return true;
          } catch (err) {
            message.error(err.response?.data?.message || '保存失败');
            return false;
          }
        }}
      >
        <div className="admin-form-grid-2">
          <ProFormText name="name" label="分类名称" rules={[{ required: true, message: '请输入分类名称' }]} />
          <ProFormText name="slug" label="网址标识（Slug）" placeholder="留空自动生成" />
        </div>
        <ProFormDigit name="sort_order" label="排序权重" min={0} extra="数值越小越靠前" />
      </ModalForm>
    </>
  );
}

export default function Articles() {
  const canWrite = useWritePermission('content');
  const actionRef = useRef();
  const articleFormRef = useRef();
  const editRequest = useRef(null);
  useEffect(() => () => editRequest.current?.abort(), []);
  const [searchParams, setSearchParams] = useSearchParams();
  const activeTab = searchParams.get('tab') === 'categories' ? 'categories' : 'articles';
  const [drawerVisible, setDrawerVisible] = useState(false);
  const [editingRecord, setEditingRecord] = useState(null);
  const [categoryOptions, setCategoryOptions] = useState([]);

  const refreshCategoryOptions = useCallback(() => {
    getArticleCategories()
      .then((res) => {
        const d = res.data?.data || res.data;
        const list = d.data || d;
        if (Array.isArray(list)) {
          setCategoryOptions(list.map((c) => ({ label: c.name, value: c.id })));
        }
      })
      .catch(() => {});
  }, []);

  useEffect(() => {
    refreshCategoryOptions();
  }, [refreshCategoryOptions]);

  const columns = [
    { title: 'ID', dataIndex: 'id', width: 64, search: false },
    {
      title: '封面',
      dataIndex: 'cover_image',
      search: false,
      width: 76,
      render: (_, record) =>
        record.cover_image ? (
          <Image src={record.cover_image} width={40} height={40} style={{ objectFit: 'cover', borderRadius: 6 }} />
        ) : (
          '-'
        ),
    },
    { title: '文章标题', dataIndex: 'title' },
    {
      title: '所属分类',
      dataIndex: 'article_category_id',
      width: 140,
      render: (_, record) => record.article_category?.name || '-',
      valueType: 'select',
      fieldProps: { options: categoryOptions },
    },
    {
      title: '发布状态',
      dataIndex: 'is_published',
      valueType: 'select',
      valueEnum: { 1: { text: '已发布' }, 0: { text: '草稿' } },
      width: 96,
      render: (_, record) =>
        record.is_published ? <Tag color="green">已发布</Tag> : <Tag color="default">草稿</Tag>,
    },
    { title: '阅读量', dataIndex: 'views', search: false, width: 88 },
    { title: '创建时间', dataIndex: 'created_at', valueType: 'dateTime', search: false, width: 175 },
    {
      title: '操作',
      valueType: 'option',
      width: 140,
      render: (_, record) =>
        canWrite
          ? [
              <Button
                type="link"
                htmlType="button"
                style={{ padding: 0, height: 'auto' }}
                key="edit"
                onClick={async () => {
                  editRequest.current?.abort();
                  const controller = new AbortController();
                  editRequest.current = controller;
                  try {
                    const res = await getArticle(record.id, { signal: controller.signal });
                    if (!controller.signal.aborted) {
                      setEditingRecord(res.data);
                      setDrawerVisible(true);
                    }
                  } catch (err) {
                    if (!controller.signal.aborted) {
                      message.error(err.response?.data?.message || '加载文章详情失败');
                    }
                  }
                }}
              >
                编辑
              </Button>,
              <Popconfirm
                key="delete"
                title="确认删除该文章？"
                onConfirm={async () => {
                  try {
                    await deleteArticle(record.id);
                    message.success('文章已删除');
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

  const articlesTab = (
    <>
      <ProTable
        actionRef={actionRef}
        rowKey="id"
        columns={columns}
        scroll={{ x: 'max-content' }}
        form={{ name: 'articles-search' }}
        search={{ labelWidth: 'auto' }}
        request={async (params) => {
          const res = await getArticles({ page: params.current, per_page: params.pageSize, ...params });
          return toTablePage(res);
        }}
        toolBarRender={() =>
          canWrite
            ? [
                <Button
                  key="add"
                  type="primary"
                  icon={<PlusOutlined />}
                  onClick={() => {
                    editRequest.current?.abort();
                    setEditingRecord(null);
                    setDrawerVisible(true);
                  }}
                >
                  新增文章
                </Button>,
              ]
            : []
        }
      />
      <DrawerForm
        name="article-editor"
        formRef={articleFormRef}
        key={editingRecord?.id || 'new'}
        title={editingRecord ? '编辑文章内容' : '新增文章'}
        open={drawerVisible}
        onOpenChange={setDrawerVisible}
        initialValues={editingRecord || { is_published: false }}
        drawerProps={{ destroyOnHidden: true, width: 'min(840px, 100vw)' }}
        onFinish={async (values) => {
          try {
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
              message.success('文章已更新');
            } else {
              await createArticle(payload);
              message.success('文章已创建');
            }
            actionRef.current?.reload();
            return true;
          } catch (err) {
            message.error(err.response?.data?.message || '保存失败');
            return false;
          }
        }}
      >
        <div className="admin-form-section-title">基础与发布信息</div>
        <div className="admin-form-grid-2">
          <ProFormText name="title" label="文章标题" rules={[{ required: true, message: '请输入文章标题' }]} />
          <ProFormSelect
            name="article_category_id"
            label="所属分类"
            options={categoryOptions}
            rules={[{ required: true, message: '请选择文章分类' }]}
          />
        </div>
        <div className="admin-form-grid-2">
          <ProFormText name="slug" label="网址标识（Slug）" placeholder="留空自动生成" />
          <ProFormSwitch name="is_published" label="立即发布展示" />
        </div>
        <ProFormTextArea name="summary" label="内容摘要" fieldProps={{ rows: 3 }} placeholder="展示在文章列表与搜索引擎摘要中的简短说明" />
        <ProForm.Item name="cover_image" label="文章封面图">
          <ImageUploader />
        </ProForm.Item>

        <div className="admin-form-section-title">正文内容与商品卡片嵌入</div>
        <ProductEmbedPicker formRef={articleFormRef} />
        <ProForm.Item name="content" label="正文内容" rules={[{ required: true, message: '请输入正文内容' }]}>
          <RichTextEditor placeholder="请输入公告、使用指南或常见问题说明" height={360} />
        </ProForm.Item>

        <div className="admin-form-section-title">搜索引擎优化（SEO）</div>
        <div className="admin-form-grid-2">
          <ProFormText name="seo_title" label="自定义 SEO 标题" placeholder="留空默认使用文章标题" />
          <ProFormText name="seo_keywords" label="SEO 关键词" placeholder="多个关键词使用英文逗号分隔" />
        </div>
        <ProFormTextArea name="seo_description" label="自定义 SEO 描述" fieldProps={{ rows: 3 }} />
      </DrawerForm>
    </>
  );

  return (
    <div className="admin-surface-tabs">
      <Tabs
        activeKey={activeTab}
        destroyOnHidden
        onChange={(key) => setSearchParams(key === 'categories' ? { tab: 'categories' } : {})}
        items={[
          { key: 'articles', label: '文章列表', children: articlesTab },
          { key: 'categories', label: '文章分类', children: <ArticleCategoriesPanel canWrite={canWrite} onChanged={refreshCategoryOptions} /> },
        ]}
      />
    </div>
  );
}
