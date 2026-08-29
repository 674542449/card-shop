import React, { useRef, useState, useEffect } from 'react';
import dayjs from 'dayjs';
import {
  ProTable,
  ModalForm,
  ProFormText,
  ProFormSelect,
  ProFormDigit,
  ProFormSwitch,
  ProFormDateTimePicker,
} from '@ant-design/pro-components';
import { Button, message, Popconfirm, Tag } from 'antd';
import { PlusOutlined } from '@ant-design/icons';
import { getCoupons, createCoupon, updateCoupon, deleteCoupon, getProducts } from '../services/api';

export default function Coupons() {
  const actionRef = useRef();
  const [modalVisible, setModalVisible] = useState(false);
  const [editingRecord, setEditingRecord] = useState(null);
  const [productOptions, setProductOptions] = useState([]);

  useEffect(() => {
    getProducts({ per_page: 200 })
      .then((res) => {
        const d = res.data?.data || res.data;
        const list = d.data || d;
        setProductOptions([
          { label: '全部商品', value: '' },
          ...list.map((p) => ({ label: p.name, value: p.id })),
        ]);
      })
      .catch(() => {});
  }, []);

  const columns = [
    { title: 'ID', dataIndex: 'id', width: 60, search: false },
    { title: '优惠码', dataIndex: 'code', copyable: true },
    {
      title: '类型',
      dataIndex: 'type',
      width: 100,
      valueType: 'select',
      valueEnum: {
        fixed: { text: '固定金额' },
        percent: { text: '百分比' },
      },
      render: (_, record) =>
        record.type === 'percent' ? <Tag color="blue">百分比</Tag> : <Tag color="green">固定金额</Tag>,
    },
    {
      title: '优惠值',
      dataIndex: 'value',
      search: false,
      width: 100,
      render: (_, record) =>
        record.type === 'percent' ? `${record.value}%` : `¥${record.value}`,
    },
    {
      title: '适用商品',
      dataIndex: 'product_id',
      search: false,
      render: (_, record) => record.product?.name || '全部商品',
    },
    {
      title: '使用情况',
      dataIndex: 'used_count',
      search: false,
      width: 100,
      render: (_, record) => `${record.used_count || 0}/${record.max_uses || '∞'}`,
    },
    {
      title: '状态',
      dataIndex: 'is_active',
      search: false,
      width: 80,
      render: (_, record) =>
        record.is_active ? <Tag color="green">启用</Tag> : <Tag color="red">禁用</Tag>,
    },
    {
      title: '过期时间',
      dataIndex: 'expires_at',
      search: false,
      width: 180,
      // Reading the node instead of the record made this dead: ProTable renders a null
      // cell as "-", which is truthy, so "永不过期" never appeared.
      render: (_, record) =>
        record.expires_at ? dayjs(record.expires_at).format('YYYY-MM-DD HH:mm') : '永不过期',
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
          title="确认删除此优惠券？"
          onConfirm={async () => {
            try {
              await deleteCoupon(record.id);
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
        actionRef={actionRef}
        rowKey="id"
        columns={columns}
        search={{ labelWidth: 'auto' }}
        request={async (params) => {
          const res = await getCoupons({ page: params.current, per_page: params.pageSize, ...params });
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
              setModalVisible(true);
            }}
          >
            新增优惠券
          </Button>,
        ]}
      />
      <ModalForm
        title={editingRecord ? '编辑优惠券' : '新增优惠券'}
        open={modalVisible}
        onOpenChange={setModalVisible}
        initialValues={editingRecord || { type: 'fixed', is_active: true }}
        modalProps={{ destroyOnClose: true }}
        onFinish={async (values) => {
          try {
            // omitNil removes cleared fields from `values`, so without restoring them
            // here "clear the expiry" and "reset the usage limit" both save as no-ops.
            // 0 is the unlimited sentinel for max_uses, and the column is NOT NULL.
            const data = {
              ...values,
              product_id: values.product_id || null,
              expires_at: values.expires_at ?? null,
              max_uses: values.max_uses ?? 0,
            };
            if (editingRecord) {
              await updateCoupon(editingRecord.id, data);
              message.success('更新成功');
            } else {
              await createCoupon(data);
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
        <ProFormText name="code" label="优惠码" rules={[{ required: true, message: '请输入优惠码' }]} />
        <ProFormSelect
          name="type"
          label="类型"
          options={[
            { label: '固定金额', value: 'fixed' },
            { label: '百分比', value: 'percent' },
          ]}
          rules={[{ required: true, message: '请选择类型' }]}
        />
        <ProFormDigit name="value" label="优惠值" min={0.01} rules={[{ required: true, message: '请输入优惠值' }]} extra="固定金额填写金额数值，百分比填写百分比数值(如10表示10%)" />
        <ProFormSelect name="product_id" label="适用商品" options={productOptions} placeholder="留空表示全部商品" />
        <ProFormDigit name="max_uses" label="最大使用次数" min={0} placeholder="留空表示不限" />
        <ProFormSwitch name="is_active" label="启用" />
        <ProFormDateTimePicker name="expires_at" label="过期时间" placeholder="留空表示永不过期" />
      </ModalForm>
    </>
  );
}
