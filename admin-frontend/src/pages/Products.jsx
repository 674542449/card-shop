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
} from '@ant-design/pro-components';
import { Button, message, Popconfirm, Tag, Image } from 'antd';
import { PlusOutlined } from '@ant-design/icons';
import { useNavigate } from 'react-router-dom';
import { getProducts, createProduct, updateProduct, deleteProduct, getCategories } from '../services/api';
import ImageUploader from '../components/ImageUploader';
import RichTextEditor from '../components/RichTextEditor';

export default function Products() {
  const actionRef = useRef();
  const navigate = useNavigate();
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
    { title: '库存', dataIndex: 'stock_count', search: false, width: 80 },
    {
      title: '状态',
      dataIndex: 'is_active',
      search: false,
      width: 80,
      render: (_, record) =>
        record.is_active ? <Tag color="green">上架</Tag> : <Tag color="red">下架</Tag>,
    },
    { title: '排序', dataIndex: 'sort_order', search: false, width: 80 },
    {
      title: '操作',
      valueType: 'option',
      width: 200,
      render: (_, record) => [
        <a key="cards" onClick={() => navigate(`/admin/products/${record.id}/cards`)}>
          卡密管理
        </a>,
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
          <a style={{ color: '#ff4d4f' }}>删除</a>
        </Popconfirm>,
      ],
    },
  ];

  return (
    <>
      <ProTable
        headerTitle="商品管理"
        actionRef={actionRef}
        rowKey="id"
        columns={columns}
        search={{ labelWidth: 'auto' }}
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
            新增商品
          </Button>,
        ]}
      />
      <DrawerForm
        title={editingRecord ? '编辑商品' : '新增商品'}
        open={drawerVisible}
        onOpenChange={setDrawerVisible}
        initialValues={editingRecord || { sort_order: 0, is_active: true, min_quantity: 1, max_quantity: 10 }}
        drawerProps={{ destroyOnClose: true, width: 720 }}
        onFinish={async (values) => {
          try {
            // omitNil drops null values from `values`, so a cleared image would submit
            // no `image` key and the old URL would silently stay.
            const payload = { ...values, image: values.image ?? null };

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
        <ProFormText name="slug" label="Slug" rules={[{ required: true, message: '请输入 Slug' }]} />
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
        <ProFormDigit name="min_quantity" label="最小购买数量" min={1} />
        <ProFormDigit name="max_quantity" label="最大购买数量" min={1} />
        <ProFormSwitch name="is_active" label="上架" />
        <ProFormDigit name="sort_order" label="排序" min={0} />
        <ProFormText name="seo_title" label="SEO 标题" />
        <ProFormTextArea name="seo_description" label="SEO 描述" />
        <ProFormText name="seo_keywords" label="SEO 关键词" />
      </DrawerForm>
    </>
  );
}
