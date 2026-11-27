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
  ProFormRadio,
  ProFormDependency,
  ProFormList,
} from '@ant-design/pro-components';
import { Button, message, Popconfirm, Tag, Image, Tabs, Space, Typography, Alert, Dropdown, Modal } from 'antd';
import {
  PlusOutlined,
  EditOutlined,
  DollarOutlined,
  KeyOutlined,
  DownOutlined,
  PauseCircleOutlined,
  PlayCircleOutlined,
  DeleteOutlined,
  ClearOutlined,
  DownloadOutlined,
} from '@ant-design/icons';
import { useSearchParams, useOutletContext } from 'react-router-dom';
import Link from '../components/PermissionLink';
import { allows } from '../permissions';
import {
  getProducts,
  getProduct,
  createProduct,
  updateProduct,
  batchUpdateProductDescription,
  batchUpdateProductPrice,
  deleteProduct,
  getCategories,
  createCategory,
  updateCategory,
  deleteCategory,
  bulkCardAction,
  exportCards,
} from '../services/api';
import ImageUploader from '../components/ImageUploader';
import RichTextEditor from '../components/RichTextEditor';
import AllCardsPanel, { downloadBlobResponse } from '../components/AllCardsPanel';
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
  const admin = useOutletContext();
  const canWrite = useWritePermission('catalog');
  const canReadCards = allows(admin, 'cards');
  const canWriteCards = allows(admin, 'cards', 'write');
  const actionRef = useRef();
  const batchFormRef = useRef();
  const editRequest = useRef(null);
  const templateRequest = useRef(null);
  useEffect(() => () => {
    editRequest.current?.abort();
    templateRequest.current?.abort();
  }, []);
  const [searchParams, setSearchParams] = useSearchParams();
  const requestedTab = searchParams.get('tab');
  const activeTab =
    requestedTab === 'categories'
      ? 'categories'
      : requestedTab === 'cards' && canReadCards
      ? 'cards'
      : 'products';
  const requestedActive = searchParams.get('is_active');
  const initialActive = ['0', '1'].includes(requestedActive) ? requestedActive : undefined;
  const lowStockOnly = searchParams.get('low_stock') === '1';
  const [drawerVisible, setDrawerVisible] = useState(false);
  const [editingRecord, setEditingRecord] = useState(null);
  const [selectedRowKeys, setSelectedRowKeys] = useState([]);
  const [selectedRowsMap, setSelectedRowsMap] = useState({});
  const [batchDescVisible, setBatchDescVisible] = useState(false);
  const [batchPriceVisible, setBatchPriceVisible] = useState(false);
  const [batchCardsBusy, setBatchCardsBusy] = useState(false);
  const [loadingTemplate, setLoadingTemplate] = useState(false);
  const [categoryOptions, setCategoryOptions] = useState([]);

  const clearSelection = useCallback(() => {
    setSelectedRowKeys([]);
    setSelectedRowsMap({});
  }, []);

  const confirmProductsCardAction = useCallback(
    (actionKey, title, detail, danger = false) => {
      if (selectedRowKeys.length === 0) return;
      Modal.confirm({
        title: `${title}（已选 ${selectedRowKeys.length} 个商品）`,
        content: detail,
        okText: danger ? '确认执行' : '确认',
        cancelText: '取消',
        okButtonProps: { danger },
        onOk: async () => {
          setBatchCardsBusy(true);
          try {
            const res = await bulkCardAction({
              action: actionKey,
              scope: 'products',
              product_ids: selectedRowKeys,
            });
            message.success(res.data?.message || `${title}已完成`);
            clearSelection();
            actionRef.current?.reload();
          } catch (err) {
            message.error(err.response?.data?.message || `${title}失败`, 6);
          } finally {
            setBatchCardsBusy(false);
          }
        },
      });
    },
    [selectedRowKeys, clearSelection]
  );

  const exportSelectedProductsCards = useCallback(
    async (format, status) => {
      if (selectedRowKeys.length === 0) return;
      setBatchCardsBusy(true);
      try {
        const res = await exportCards({
          format,
          ...(status ? { status } : {}),
          product_ids: selectedRowKeys.join(','),
        });
        downloadBlobResponse(res, `selected_products_cards_${status || 'all'}.${format}`);
        message.success('所选商品卡密导出文件已生成');
      } catch (err) {
        message.error(err.response?.data?.message || '导出卡密失败', 6);
      } finally {
        setBatchCardsBusy(false);
      }
    },
    [selectedRowKeys]
  );

  const batchCardMenuItems = [
    ...(canWriteCards
      ? [
          {
            key: 'pause_unsold',
            icon: <PauseCircleOutlined />,
            label: '一键暂停所选商品未售卡密',
            onClick: () =>
              confirmProductsCardAction(
                'pause_unsold',
                '一键暂停所选商品未售卡密',
                '所选商品的全部未售卡密将转为停用状态，可随时一键恢复；待支付锁定与已售卡密不受影响。'
              ),
          },
          {
            key: 'resume_disabled',
            icon: <PlayCircleOutlined />,
            label: '一键恢复所选商品停用卡密',
            onClick: () =>
              confirmProductsCardAction(
                'resume_disabled',
                '一键恢复所选商品停用卡密',
                '将所选商品下无订单关联的停用卡密恢复为可售状态，请确认这些卡密仍有效。'
              ),
          },
          {
            key: 'delete_unsold',
            danger: true,
            icon: <DeleteOutlined />,
            label: '一键清空所选商品未售卡密',
            onClick: () =>
              confirmProductsCardAction(
                'delete_unsold',
                '一键清空所选商品未售卡密',
                '将永久删除所选商品下的全部未售卡密且不可恢复；锁定中、已售及售后换出卡密均受保护。',
                true
              ),
          },
          {
            key: 'delete_disabled',
            danger: true,
            icon: <ClearOutlined />,
            label: '一键清空所选商品停用卡密',
            onClick: () =>
              confirmProductsCardAction(
                'delete_disabled',
                '一键清空所选商品停用卡密',
                '将永久删除所选商品下的全部停用废弃卡密且不可恢复；未售、锁定中及已售卡密不受影响。',
                true
              ),
          },
          { type: 'divider' },
        ]
      : []),
    ...(canReadCards
      ? [
          {
            key: 'export_txt',
            icon: <DownloadOutlined />,
            label: '导出所选商品未售卡密（TXT）',
            onClick: () => exportSelectedProductsCards('txt', 'unsold'),
          },
          {
            key: 'export_csv',
            icon: <DownloadOutlined />,
            label: '导出所选商品全部卡密（CSV）',
            onClick: () => exportSelectedProductsCards('csv', undefined),
          },
        ]
      : []),
  ];

  const previewAdjustedPrice = (rawPrice, mode, rawValue) => {
    const base = Number(rawPrice);
    const val = Number(rawValue);
    if (!Number.isFinite(base) || !Number.isFinite(val)) return null;
    let next;
    if (mode === 'fixed') {
      next = val;
    } else if (mode === 'delta') {
      if (val === 0) return null;
      next = base + val;
    } else if (mode === 'percent') {
      if (val === 0) return null;
      next = base * (1 + val / 100);
    } else {
      return null;
    }
    return Math.round((next + Number.EPSILON) * 100) / 100;
  };

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
                onChange: (keys, rows) => {
                  setSelectedRowKeys(keys);
                  setSelectedRowsMap((prev) => {
                    const next = {};
                    const keySet = new Set(keys);
                    for (const [k, item] of Object.entries(prev)) {
                      if (keySet.has(item.id) || keySet.has(k)) next[item.id] = item;
                    }
                    for (const row of rows || []) {
                      if (row && row.id != null) {
                        next[row.id] = { id: row.id, name: row.name, price: row.price };
                      }
                    }
                    return next;
                  });
                },
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
                    key="batchPrice"
                    icon={<DollarOutlined />}
                    onClick={() => setBatchPriceVisible(true)}
                  >
                    批量修改价格（{selectedRowKeys.length}）
                  </Button>
                ),
                selectedRowKeys.length > 0 && (
                  <Button
                    key="batchDesc"
                    icon={<EditOutlined />}
                    onClick={() => setBatchDescVisible(true)}
                  >
                    批量修改介绍（{selectedRowKeys.length}）
                  </Button>
                ),
                selectedRowKeys.length > 0 && batchCardMenuItems.length > 0 && (
                  <Dropdown key="batchCards" menu={{ items: batchCardMenuItems }} trigger={['click']}>
                    <Button icon={<KeyOutlined />} loading={batchCardsBusy}>
                      批量管理卡密（{selectedRowKeys.length}） <DownOutlined />
                    </Button>
                  </Dropdown>
                ),
                selectedRowKeys.length > 0 && (
                  <Button
                    key="clearSelection"
                    onClick={clearSelection}
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
        name="product-batch-price"
        title={`批量修改商品价格（已选 ${selectedRowKeys.length} 个商品）`}
        open={batchPriceVisible}
        onOpenChange={setBatchPriceVisible}
        initialValues={{ mode: 'fixed', wholesale_strategy: 'scale' }}
        modalProps={{ destroyOnHidden: true, width: 'min(620px, 100vw)' }}
        onFinish={async (values) => {
          if (selectedRowKeys.length === 0) {
            message.warning('请先勾选需要修改价格的商品');
            return false;
          }
          try {
            const res = await batchUpdateProductPrice(selectedRowKeys, {
              mode: values.mode,
              value: values.value,
              wholesale_strategy: values.wholesale_strategy || 'scale',
            });
            message.success(res.data?.message || '已批量更新商品价格');
            clearSelection();
            actionRef.current?.reload();
            return true;
          } catch (err) {
            const firstFieldError = Object.values(err.response?.data?.errors || {}).flat()[0];
            message.error(firstFieldError || err.response?.data?.message || '批量修改商品价格失败');
            return false;
          }
        }}
      >
        <Typography.Text type="secondary" style={{ display: 'block', marginBottom: 14 }}>
          确认后将按以下规则统一调整所选 {selectedRowKeys.length} 个商品的销售单价，商品名称、分类、介绍与库存均不受影响；任一商品调价不合法时整批自动取消。
        </Typography.Text>
        <ProFormRadio.Group
          name="mode"
          label="调价方式"
          radioType="button"
          options={[
            { label: '统一设为固定价', value: 'fixed' },
            { label: '按金额加减', value: 'delta' },
            { label: '按百分比调价', value: 'percent' },
          ]}
          rules={[{ required: true, message: '请选择调价方式' }]}
        />
        <ProFormDependency name={['mode']}>
          {({ mode }) => {
            if (mode === 'delta') {
              return (
                <ProFormDigit
                  name="value"
                  label="增减金额（CNY）"
                  min={-99999999.99}
                  max={99999999.99}
                  fieldProps={{ precision: 2, addonAfter: '元', placeholder: '例如 10 表示涨价 10 元，-15 表示降价 15 元' }}
                  extra="正数表示统一加价，负数表示统一降价（不能为 0；任意商品调后单价不能低于 ¥0.01）。"
                  rules={[
                    { required: true, message: '请输入增减金额' },
                    {
                      validator: (_, v) =>
                        v === 0 ? Promise.reject(new Error('调价金额不能为 0')) : Promise.resolve(),
                    },
                  ]}
                />
              );
            }
            if (mode === 'percent') {
              return (
                <ProFormDigit
                  name="value"
                  label="涨跌百分比（%）"
                  min={-99.99}
                  max={1000}
                  fieldProps={{ precision: 2, addonAfter: '%', placeholder: '例如 10 表示上浮 10%，-15 表示打 85 折' }}
                  extra="正数表示按比例涨价，负数表示按比例降价（例如 -10 为九折，四舍五入保留两位小数）。"
                  rules={[
                    { required: true, message: '请输入调价百分比' },
                    {
                      validator: (_, v) =>
                        v === 0 ? Promise.reject(new Error('调价百分比不能为 0')) : Promise.resolve(),
                    },
                  ]}
                />
              );
            }
            return (
              <ProFormDigit
                name="value"
                label="统一销售单价（CNY）"
                min={0.01}
                max={99999999.99}
                fieldProps={{ precision: 2, addonAfter: '元', placeholder: '请输入统一目标售价，最低 0.01' }}
                extra="将所有勾选商品的基础销售单价统一修改为此数值。"
                rules={[{ required: true, message: '请输入目标销售单价' }]}
              />
            );
          }}
        </ProFormDependency>
        <ProFormSelect
          name="wholesale_strategy"
          label="阶梯批发价处理方式"
          allowClear={false}
          options={[
            { label: '按新旧单价同比例调整阶梯价（推荐）', value: 'scale' },
            { label: '保持原阶梯价不变（新单价低于现有阶梯价时拦阻）', value: 'keep' },
            { label: '清空所选商品的阶梯批发价', value: 'clear' },
          ]}
          extra="若勾选商品已配置多件批发价，默认同比例缩放以维持原有折扣梯度并避免价格倒挂。"
        />
        <ProFormDependency name={['mode', 'value']}>
          {({ mode, value }) => {
            const previewItems = selectedRowKeys
              .map((id) => selectedRowsMap[id])
              .filter(Boolean)
              .slice(0, 5);
            if (previewItems.length === 0 || value === undefined || value === null || value === '') {
              return null;
            }
            const rows = previewItems.map((item) => {
              const next = previewAdjustedPrice(item.price, mode, value);
              return { ...item, next, invalid: next === null || next < 0.01 || next > 99999999.99 };
            });
            const hasInvalid = rows.some((r) => r.invalid);
            return (
              <Alert
                type={hasInvalid ? 'error' : 'info'}
                showIcon
                style={{ marginTop: 4 }}
                message={hasInvalid ? '部分商品调价结果超出合法范围（最低 ¥0.01）' : '调价预览（前 5 个已选商品）'}
                description={
                  <div style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
                    {rows.map((r) => (
                      <div key={r.id} style={{ display: 'flex', justifyContent: 'space-between', gap: 12, fontSize: 13 }}>
                        <span style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{r.name}</span>
                        <span className="admin-amount" style={{ flexShrink: 0 }}>
                          ¥{Number(r.price).toFixed(2)} →{' '}
                          <strong style={{ color: r.invalid ? '#cf1322' : undefined }}>
                            {r.next === null ? '无效' : `¥${r.next.toFixed(2)}`}
                          </strong>
                        </span>
                      </div>
                    ))}
                    {selectedRowKeys.length > previewItems.length && (
                      <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                        其余 {selectedRowKeys.length - previewItems.length} 个商品将在提交时由服务端一并校验并原子更新。
                      </Typography.Text>
                    )}
                  </div>
                }
              />
            );
          }}
        </ProFormDependency>
      </ModalForm>
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
            clearSelection();
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
        onChange={(key) => setSearchParams(key === 'products' ? {} : { tab: key })}
        items={[
          { key: 'products', label: '商品列表', children: productsTab },
          { key: 'categories', label: '商品分类', children: <CategoriesPanel canWrite={canWrite} onChanged={refreshCategoryOptions} /> },
          ...(canReadCards
            ? [
                {
                  key: 'cards',
                  label: '全部卡密',
                  children: (
                    <AllCardsPanel
                      canWrite={canWriteCards}
                      categoryOptions={categoryOptions}
                      onInventoryChanged={() => actionRef.current?.reload()}
                    />
                  ),
                },
              ]
            : []),
        ]}
      />
    </div>
  );
}
