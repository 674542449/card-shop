import useWritePermission from '../hooks/useWritePermission';
import React, { useRef, useState, useEffect } from 'react';
import {
  ProTable,
  DrawerForm,
  ProForm,
  ProFormText,
  ProFormTextArea,
  ProFormDigit,
  ProFormSwitch,
  ProFormSelect,
  ProFormList,
} from '@ant-design/pro-components';
import { Button, message, Popconfirm, Tag, Image } from 'antd';
import { PlusOutlined } from '@ant-design/icons';
import { Link, useSearchParams, useOutletContext } from 'react-router-dom';
import { allows } from '../permissions';
import { getProducts, getProduct, createProduct, updateProduct, deleteProduct, getCategories } from '../services/api';
import ImageUploader from '../components/ImageUploader';
import RichTextEditor from '../components/RichTextEditor';

export default function Products() {
  const canWrite = useWritePermission('catalog');
  const canReadCards = allows(useOutletContext(), 'cards');
  const actionRef = useRef();
  const [searchParams] = useSearchParams();
  const requestedActive = searchParams.get('is_active');
  const initialActive = ['0', '1'].includes(requestedActive) ? requestedActive : undefined;
  const lowStockOnly = searchParams.get('low_stock') === '1';
  const [drawerVisible, setDrawerVisible] = useState(false);
  const [editingRecord, setEditingRecord] = useState(null);
  const [categoryOptions, setCategoryOptions] = useState([]);

  useEffect(() => {
    getCategories({ per_page: 100 })
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
      title: '图片',
      dataIndex: 'image',
      search: false,
      width: 80,
      render: (_, record) =>
        record.image ? (
          <Image src={record.image} width={40} height={40} style={{ objectFit: 'cover', borderRadius: 4 }} />
        ) : (
          '-'
        ),
    },
    { title: '名称', dataIndex: 'name' },
    { title: '库存预警', dataIndex: 'low_stock', hideInTable: true, valueType: 'select', valueEnum: { 1: { text: '仅预警商品' }, 0: { text: '全部' } } },
    {
      title: '分类',
      dataIndex: 'category_id',
      render: (_, record) => record.category?.name || '-',
      valueType: 'select',
      fieldProps: { options: categoryOptions },
    },
    {
      title: '价格',
      dataIndex: 'price',
      search: false,
      width: 100,
      render: (_, record) => `¥${record.price}`,
    },
    { title: '库存', dataIndex: 'stock_count', search: false, width: 110, render: (_, record) => <span>{record.stock_count} {record.is_active && record.low_stock_threshold != null && record.stock_count <= record.low_stock_threshold && <Tag color="orange">预警</Tag>}</span> },
    {
      title: '状态',
      dataIndex: 'is_active',
      valueType: 'select',
      valueEnum: { 1: { text: '上架' }, 0: { text: '下架' } },
      width: 80,
      render: (_, record) =>
        record.is_active ? <Tag color="green">上架</Tag> : <Tag color="red">下架</Tag>,
    },
    { title: '排序', dataIndex: 'sort_order', search: false, width: 80 },
    {
      title: '操作',
      valueType: 'option',
      width: 200,
      render: (_, record) => canWrite ? [
        // 同 Orders.jsx：不要带 /admin 前缀，basename 已经包含它。
        canReadCards && <Link key="cards" to={`/products/${record.id}/cards`}>
          卡密管理
        </Link>,
        <Button type="link" htmlType="button" style={{ padding: 0, height: 'auto' }}
          key="edit"
          onClick={async () => {
            try {
              const res = await getProduct(record.id);
              setEditingRecord(res.data);
              setDrawerVisible(true);
            } catch (err) {
              message.error(err.response?.data?.message || '加载商品失败');
            }
          }}
        >
          编辑
        </Button>,
        <Popconfirm
          key="delete"
          title="确认删除此商品？"
          onConfirm={async () => {
            try {
              await deleteProduct(record.id);
              message.success('删除成功');
              actionRef.current?.reload();
            } catch (err) {
              message.error(err.response?.data?.message || '删除失败');
            }
          }}
        >
          <Button type="link" htmlType="button" danger style={{ padding: 0, height: 'auto' }}>删除</Button>
        </Popconfirm>,
      ] : (canReadCards ? [<Link key="cards" to={`/products/${record.id}/cards`}>卡密查看</Link>] : []),
    },
  ];

  return (
    <>
      <ProTable
        key={`products-${initialActive ?? 'all'}-${lowStockOnly}`}
        actionRef={actionRef}
        rowKey="id"
        columns={columns}
        search={{ labelWidth: 'auto' }}
        form={{ name: 'products-search', initialValues: { is_active: initialActive, low_stock: lowStockOnly ? '1' : undefined } }}
        request={async (params) => {
          const res = await getProducts({ page: params.current, per_page: params.pageSize, ...params });
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
            新增商品
          </Button>,
        ] : []}
      />
      <DrawerForm
        name="product-editor"
        key={editingRecord?.id || 'new'}
        title={editingRecord ? '编辑商品' : '新增商品'}
        open={drawerVisible}
        onOpenChange={setDrawerVisible}
        initialValues={editingRecord || { sort_order: 0, is_active: true, min_quantity: 1, max_quantity: 10, low_stock_threshold: 5 }}
        drawerProps={{ destroyOnClose: true, width: 720 }}
        onFinish={async (values) => {
          try {
            // omitNil drops null values from `values`, so a cleared image would submit
            // no `image` key and the old URL would silently stay.
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
              message.success('更新成功');
            } else {
              await createProduct(payload);
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
        <ProFormText name="name" label="商品名称" rules={[{ required: true, message: '请输入商品名称' }]} />
        <ProFormText name="slug" label="网址标识（Slug）" placeholder="留空自动生成" extra="自定义标识建议使用英文、数字和连字符。" />
        <ProFormSelect name="category_id" label="分类" options={categoryOptions} rules={[{ required: true, message: '请选择分类' }]} />
        <ProForm.Item name="image" label="商品图片">
          <ImageUploader />
        </ProForm.Item>
        <ProForm.Item name="description" label="描述">
          <RichTextEditor placeholder="请输入商品描述" />
        </ProForm.Item>
        {/* min matches the server's numeric|min:0.01, so 0 is rejected in the field
            rather than on a round trip. */}
        <ProFormDigit name="price" label="价格" min={0.01} rules={[{ required: true, message: '请输入价格' }]} fieldProps={{ precision: 2 }} />
        <ProFormDigit name="min_quantity" label="最小购买数量" min={1} fieldProps={{ precision: 0 }} />
        <ProFormDigit name="low_stock_threshold" label="低库存预警阈值" min={0} max={100000} fieldProps={{ precision: 0 }} extra="库存不高于此值时预警；0 表示仅售罄时预警，清空关闭。Telegram 配置后会推送，同一轮低库存只通知一次。" />
        <ProFormDigit name="max_quantity" label="最大购买数量" min={1} fieldProps={{ precision: 0 }} />
        <ProFormList name="wholesale_prices" label="阶梯批发价" creatorButtonProps={{ creatorButtonText: '添加阶梯价' }}>
          <ProForm.Group>
            <ProFormDigit name="min_quantity" label="起购数量" min={2} fieldProps={{ precision: 0 }} rules={[{ required: true, message: '请输入起购数量' }]} />
            <ProFormDigit name="price" label="单价" min={0.01} fieldProps={{ precision: 2 }} rules={[{ required: true, message: '请输入单价' }]} />
          </ProForm.Group>
        </ProFormList>
        <ProFormSwitch name="is_active" label="上架" />
        <ProFormDigit name="sort_order" label="排序" min={0} />
        <ProFormText name="seo_title" label="SEO 标题" />
        <ProFormTextArea name="seo_description" label="SEO 描述" />
        <ProFormText name="seo_keywords" label="SEO 关键词" />
      </DrawerForm>
    </>
  );
}
