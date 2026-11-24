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
  ProFormList,
} from '@ant-design/pro-components';
import { Button, message, Popconfirm, Tag, Image, Tabs, Space, Typography } from 'antd';
import { PlusOutlined, EditOutlined } from '@ant-design/icons';
import { useSearchParams, useOutletContext } from 'react-router-dom';
import Link from '../components/PermissionLink';
import { allows } from '../permissions';
import {
  getProducts,
  getProduct,
  createProduct,
  updateProduct,
  batchUpdateProductDescription,
  deleteProduct,
  getCategories,
  createCategory,
  updateCategory,
  deleteCategory,
} from '../services/api';
import ImageUploader from '../components/ImageUploader';
import RichTextEditor from '../components/RichTextEditor';
import { toTablePage } from '../utils/orderMeta';

function CategoriesPanel({ canWrite, onChanged }) {
  const actionRef = useRef();
  const [modalVisible, setModalVisible] = useState(false);
  const [editingRecord, setEditingRecord] = useState(null);

  const columns = [
    { title: 'ID', dataIndex: 'id', width: 64, search: false },
    {
      title: '缩略图',
      dataIndex: 'image',
      search: false,
      width: 84,
      render: (_, record) =>
        record.image ? (
          <Image src={record.image} width={40} height={40} style={{ objectFit: 'cover', borderRadius: 6 }} />
        ) : (
          '-'
        ),
    },
    { title: '分类名称', dataIndex: 'name', copyable: true },
    { title: '网址标识（Slug）', dataIndex: 'slug', search: false },
    { title: '排序权重', dataIndex: 'sort_order', search: false, width: 95 },
    { title: '关联商品数', dataIndex: 'products_count', search: false, width: 105 },
    {
      title: '状态',
      dataIndex: 'is_active',
      valueType: 'select',
      valueEnum: { 1: { text: '启用' }, 0: { text: '停用' } },
      width: 90,
      render: (_, record) =>
        record.is_active ? <Tag color="green">启用</Tag> : <Tag color="red">停用</Tag>,
    },
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
                title="确认删除该商品分类？"
                onConfirm={async () => {
                  try {
                    await deleteCategory(record.id);
                    message.success('分类已删除');
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
        form={{ name: 'categories-search' }}
        search={{ labelWidth: 'auto' }}
        request={async (params) => {
          const res = await getCategories({ page: params.current, per_page: params.pageSize, ...params });
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
        name="category-editor"
        key={editingRecord?.id || 'new'}
        title={editingRecord ? '编辑商品分类' : '新增商品分类'}
        open={modalVisible}
        onOpenChange={setModalVisible}
        initialValues={editingRecord || { sort_order: 0, is_active: true }}
        modalProps={{ destroyOnHidden: true }}
        onFinish={async (values) => {
          try {
            const payload = {
              ...values,
              image: values.image ?? null,
              description: values.description ?? null,
              sort_order: values.sort_order ?? 0,
            };
            if (editingRecord) {
              await updateCategory(editingRecord.id, payload);
              message.success('分类已更新');
            } else {
              await createCategory(payload);
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
          <ProFormText
            name="slug"
            label="网址标识（Slug）"
            placeholder="留空自动生成，如 digital-goods"
            extra="建议使用英文、数字与连字符。"
          />
        </div>
        <ProFormTextArea
          name="description"
          label="分类说明"
          fieldProps={{ rows: 3 }}
          extra="展示在前台对应商品分类视图顶部。"
        />
        <div className="admin-form-grid-3">
          <ProForm.Item name="image" label="分类图标">
            <ImageUploader />
          </ProForm.Item>
          <ProFormDigit name="sort_order" label="排序权重" min={0} extra="数值越小越靠前" />
          <ProFormSwitch name="is_active" label="启用状态" />
        </div>
      </ModalForm>
    </>
  );
}

export default function Products() {
  const canWrite = useWritePermission('catalog');
  const canReadCards = allows(useOutletContext(), 'cards');
  const actionRef = useRef();
  const batchFormRef = useRef();
  const editRequest = useRef(null);
  const templateRequest = useRef(null);
  useEffect(() => () => {
    editRequest.current?.abort();
    templateRequest.current?.abort();
  }, []);
  const [searchParams, setSearchParams] = useSearchParams();
  const activeTab = searchParams.get('tab') === 'categories' ? 'categories' : 'products';
  const requestedActive = searchParams.get('is_active');
  const initialActive = ['0', '1'].includes(requestedActive) ? requestedActive : undefined;
  const lowStockOnly = searchParams.get('low_stock') === '1';
  const [drawerVisible, setDrawerVisible] = useState(false);
  const [editingRecord, setEditingRecord] = useState(null);
  const [selectedRowKeys, setSelectedRowKeys] = useState([]);
  const [batchDescVisible, setBatchDescVisible] = useState(false);
  const [loadingTemplate, setLoadingTemplate] = useState(false);
  const [categoryOptions, setCategoryOptions] = useState([]);

  const refreshCategoryOptions = useCallback(() => {
    getCategories()
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
    { title: 'ID', dataIndex: 'id', width: 56, search: false },
    {
      title: '封面',
      dataIndex: 'image',
      search: false,
      width: 64,
      render: (_, record) =>
        record.image ? (
          <Image src={record.image} width={40} height={40} style={{ objectFit: 'cover', borderRadius: 6 }} />
        ) : (
          '-'
        ),
    },
    { title: '商品名称', dataIndex: 'name', ellipsis: true },
    {
      title: '库存预警',
      dataIndex: 'low_stock',
      hideInTable: true,
      valueType: 'select',
      valueEnum: { 1: { text: '仅预警商品' }, 0: { text: '全部商品' } },
    },
    {
      title: '所属分类',
      dataIndex: 'category_id',
      width: 116,
      ellipsis: true,
      render: (_, record) => record.category?.name || '-',
      valueType: 'select',
      fieldProps: { options: categoryOptions },
    },
    {
      title: '销售单价',
      dataIndex: 'price',
      search: false,
      width: 92,
      render: (_, record) => <span className="admin-amount">¥{record.price}</span>,
    },
    {
      title: '可售库存',
      dataIndex: 'stock_count',
      search: false,
      width: 104,
      render: (_, record) => (
        <Space size={6}>
          <span className="admin-amount">{record.stock_count}</span>
          {record.is_active && record.low_stock_threshold != null && record.stock_count <= record.low_stock_threshold && (
            <Tag color="orange">预警</Tag>
          )}
        </Space>
      ),
    },
    {
      title: '销售状态',
      dataIndex: 'is_active',
      valueType: 'select',
      valueEnum: { 1: { text: '上架' }, 0: { text: '下架' } },
      width: 80,
      render: (_, record) =>
        record.is_active ? <Tag color="green">上架</Tag> : <Tag color="red">下架</Tag>,
    },
    { title: '排序', dataIndex: 'sort_order', search: false, width: 62 },
    {
      title: '操作',
      valueType: 'option',
      width: 164,
      fixed: 'right',
      render: (_, record) =>
        canWrite
          ? [
              canReadCards && (
                <Link key="cards" to={`/products/${record.id}/cards`}>
                  卡密管理
                </Link>
              ),
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
                    const res = await getProduct(record.id, { signal: controller.signal });
                    if (!controller.signal.aborted) {
                      setEditingRecord(res.data);
                      setDrawerVisible(true);
                    }
                  } catch (err) {
                    if (!controller.signal.aborted) message.error(err.response?.data?.message || '加载商品失败');
                  }
                }}
              >
                编辑
              </Button>,
              <Popconfirm
                key="delete"
                title="确认删除该商品？"
                onConfirm={async () => {
                  try {
                    await deleteProduct(record.id);
                    message.success('商品已删除');
                    setSelectedRowKeys((prev) => prev.filter((k) => k !== record.id));
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
          : canReadCards
          ? [<Link key="cards" to={`/products/${record.id}/cards`}>卡密查看</Link>]
          : [],
    },
  ];

  const productsTab = (
    <>
      <ProTable
        key={`products-${initialActive ?? 'all'}-${lowStockOnly}`}
        actionRef={actionRef}
        rowKey="id"
        columns={columns}
        scroll={{ x: 'max-content' }}
        search={{ labelWidth: 'auto' }}
        pagination={{ defaultPageSize: 20, showSizeChanger: true, pageSizeOptions: [20, 50, 100] }}
        rowSelection={
          canWrite
            ? {
                selectedRowKeys,
                onChange: setSelectedRowKeys,
                preserveSelectedRowKeys: true,
              }
            : false
        }
        tableAlertRender={false}
        form={{ name: 'products-search', initialValues: { is_active: initialActive, low_stock: lowStockOnly ? '1' : undefined } }}
        request={async (params) => {
          const res = await getProducts({ page: params.current, per_page: params.pageSize, ...params });
          return toTablePage(res);
        }}
        toolBarRender={() =>
          canWrite
            ? [
                selectedRowKeys.length > 0 && (
                  <Button
                    key="batchDesc"
                    icon={<EditOutlined />}
                    onClick={() => setBatchDescVisible(true)}
                  >
                    批量修改介绍（{selectedRowKeys.length}）
                  </Button>
                ),
                selectedRowKeys.length > 0 && (
                  <Button
                    key="clearSelection"
                    onClick={() => setSelectedRowKeys([])}
                  >
                    清空已选
                  </Button>
                ),
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
                  新增商品
                </Button>,
              ]
            : []
        }
      />
      <ModalForm
        name="product-batch-description"
        formRef={batchFormRef}
        title={`批量修改商品介绍（已选 ${selectedRowKeys.length} 个商品）`}
        open={batchDescVisible}
        onOpenChange={(open) => {
          if (!open) {
            templateRequest.current?.abort();
            setLoadingTemplate(false);
          }
          setBatchDescVisible(open);
        }}
        modalProps={{ destroyOnHidden: true, width: 'min(760px, 100vw)' }}
        onFinish={async (values) => {
          if (selectedRowKeys.length === 0) {
            message.warning('请先勾选需要修改介绍的商品');
            return false;
          }
          try {
            const res = await batchUpdateProductDescription(selectedRowKeys, values.description ?? null);
            message.success(res.data?.message || '已批量更新商品介绍');
            setSelectedRowKeys([]);
            actionRef.current?.reload();
            return true;
          } catch (err) {
            message.error(err.response?.data?.message || '批量修改商品介绍失败');
            return false;
          }
        }}
      >
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12, flexWrap: 'wrap', marginBottom: 12 }}>
          <Typography.Text type="secondary">
            确认后将把所选 {selectedRowKeys.length} 个商品的「商品详细说明」统一替换为以下内容，商品名称、分类、单价与库存均不受影响。
          </Typography.Text>
          {selectedRowKeys.length > 0 && (
            <Button
              type="link"
              htmlType="button"
              loading={loadingTemplate}
              style={{ padding: 0, height: 'auto' }}
              onClick={async () => {
                templateRequest.current?.abort();
                const controller = new AbortController();
                templateRequest.current = controller;
                setLoadingTemplate(true);
                try {
                  const res = await getProduct(selectedRowKeys[0], { signal: controller.signal });
                  if (!controller.signal.aborted) {
                    batchFormRef.current?.setFieldsValue({ description: res.data?.description || '' });
                    message.success(`已载入「${res.data?.name || '首个所选商品'}」的当前介绍`);
                  }
                } catch (err) {
                  if (!controller.signal.aborted) {
                    message.error(err.response?.data?.message || '读取商品介绍失败');
                  }
                } finally {
                  if (templateRequest.current === controller) {
                    setLoadingTemplate(false);
                  }
                }
              }}
            >
              载入首个已选商品当前介绍
            </Button>
          )}
        </div>
        <ProForm.Item
          name="description"
          label="统一商品详细说明"
          rules={[{ required: true, message: '请输入要统一应用的商品详细说明' }]}
        >
          <RichTextEditor placeholder="请输入统一应用的商品规格、登录格式、交付方式与售后说明" />
        </ProForm.Item>
      </ModalForm>
      <DrawerForm
        name="product-editor"
        key={editingRecord?.id || 'new'}
        title={editingRecord ? '编辑商品信息' : '新增商品'}
        open={drawerVisible}
        onOpenChange={(open) => {
          if (!open) editRequest.current?.abort();
          setDrawerVisible(open);
        }}
        initialValues={
          editingRecord || {
            sort_order: 0,
            is_active: true,
            min_quantity: 1,
            max_quantity: 10,
            low_stock_threshold: 5,
          }
        }
        drawerProps={{ destroyOnHidden: true, width: 'min(760px, 100vw)' }}
        onFinish={async (values) => {
          try {
            const payload = {
              ...values,
              image: values.image ?? null,
              low_stock_threshold: values.low_stock_threshold ?? null,
              description: values.description ?? null,
              seo_title: values.seo_title ?? null,
              seo_description: values.seo_description ?? null,
              seo_keywords: values.seo_keywords ?? null,
              wholesale_prices: values.wholesale_prices ?? [],
            };

            if (editingRecord) {
              await updateProduct(editingRecord.id, payload);
              message.success('商品已更新');
            } else {
              await createProduct(payload);
              message.success('商品已创建');
            }
            actionRef.current?.reload();
            return true;
          } catch (err) {
            message.error(err.response?.data?.message || '保存失败');
            return false;
          }
        }}
      >
        <div className="admin-form-section-title">基础信息</div>
        <div className="admin-form-grid-2">
          <ProFormText name="name" label="商品名称" rules={[{ required: true, message: '请输入商品名称' }]} />
          <ProFormSelect name="category_id" label="所属分类" options={categoryOptions} rules={[{ required: true, message: '请选择所属分类' }]} />
        </div>
        <div className="admin-form-grid-3">
          <ProFormText name="slug" label="网址标识（Slug）" placeholder="留空自动生成" extra="建议使用英文、数字与连字符" />
          <ProFormDigit name="sort_order" label="排序权重" min={0} />
          <ProFormSwitch name="is_active" label="上架销售" />
        </div>
        <ProForm.Item name="image" label="商品封面图">
          <ImageUploader />
        </ProForm.Item>
        <ProForm.Item name="description" label="商品详细说明">
          <RichTextEditor placeholder="请输入商品规格、交付方式与使用须知" />
        </ProForm.Item>

        <div className="admin-form-section-title">定价与库存策略</div>
        <div className="admin-form-grid-2">
          <ProFormDigit name="price" label="销售单价（CNY）" min={0.01} rules={[{ required: true, message: '请输入销售单价' }]} fieldProps={{ precision: 2 }} />
          <ProFormDigit
            name="low_stock_threshold"
            label="低库存预警阈值"
            min={0}
            max={100000}
            fieldProps={{ precision: 0 }}
            extra="可售库存不高于此数值时触发预警（0 表示售罄预警，清空则关闭）"
          />
        </div>
        <div className="admin-form-grid-2">
          <ProFormDigit name="min_quantity" label="单笔起购数量" min={1} fieldProps={{ precision: 0 }} />
          <ProFormDigit name="max_quantity" label="单笔限购数量" min={1} fieldProps={{ precision: 0 }} />
        </div>
        <ProFormList name="wholesale_prices" label="阶梯批发定价" creatorButtonProps={{ creatorButtonText: '添加阶梯价格档位' }}>
          <ProForm.Group>
            <ProFormDigit name="min_quantity" label="起购数量" min={2} fieldProps={{ precision: 0 }} rules={[{ required: true, message: '请输入起购数量' }]} />
            <ProFormDigit name="price" label="优惠单价（CNY）" min={0.01} fieldProps={{ precision: 2 }} rules={[{ required: true, message: '请输入优惠单价' }]} />
          </ProForm.Group>
        </ProFormList>

        <div className="admin-form-section-title">搜索引擎优化（SEO）</div>
        <div className="admin-form-grid-2">
          <ProFormText name="seo_title" label="自定义 SEO 标题" placeholder="留空默认使用商品名称" />
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
          { key: 'products', label: '商品列表', children: productsTab },
          { key: 'categories', label: '商品分类', children: <CategoriesPanel canWrite={canWrite} onChanged={refreshCategoryOptions} /> },
        ]}
      />
    </div>
  );
}
