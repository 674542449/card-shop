import useWritePermission from '../hooks/useWritePermission';
import React, { useRef, useState, useEffect } from 'react';
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
import api, { getCoupons, createCoupon, updateCoupon, deleteCoupon } from '../services/api';
import { formatDateMinute, toTablePage } from '../utils/orderMeta';

export default function Coupons() {
  const canWrite = useWritePermission('coupons');
  const actionRef = useRef();
  const [modalVisible, setModalVisible] = useState(false);
  const [editingRecord, setEditingRecord] = useState(null);
  const [productOptions, setProductOptions] = useState([]);
  const [productSearch, setProductSearch] = useState('');
  const [productsLoading, setProductsLoading] = useState(false);

  useEffect(() => {
    let active = true;
    const timer = setTimeout(() => {
      setProductsLoading(true);
      api
        .get('/coupons/product-options', {
          params: { q: productSearch, include: editingRecord?.product_id, per_page: 50 },
        })
        .then((res) => {
          if (!active) return;
          setProductOptions([
            { label: '全站商品通用', value: '' },
            ...(res.data.data || []).map((p) => ({
              label: `${p.name}${p.is_active ? '' : '（已下架）'}`,
              value: p.id,
            })),
          ]);
        })
        .catch(() => {
          if (active) message.error('加载适用商品选项失败，请重新检索');
        })
        .finally(() => {
          if (active) setProductsLoading(false);
        });
    }, 200);
    return () => {
      active = false;
      clearTimeout(timer);
    };
  }, [productSearch, editingRecord?.product_id]);

  const columns = [
    { title: 'ID', dataIndex: 'id', width: 64, search: false },
    {
      title: '优惠券码',
      dataIndex: 'code',
      copyable: true,
      width: 190,
      render: (_, record) => <span className="admin-mono">{record.code}</span>,
    },
    {
      title: '折扣类型',
      dataIndex: 'type',
      width: 110,
      valueType: 'select',
      valueEnum: {
        fixed: { text: '固定立减' },
        percent: { text: '百分比折扣' },
      },
      render: (_, record) =>
        record.type === 'percent' ? <Tag color="blue">百分比折扣</Tag> : <Tag color="green">固定立减</Tag>,
    },
    {
      title: '优惠额度',
      dataIndex: 'value',
      search: false,
      width: 110,
      align: 'right',
      render: (_, record) => (
        <span className="admin-amount">{record.type === 'percent' ? `-${record.value}%` : `-¥${record.value}`}</span>
      ),
    },
    {
      title: '适用范围',
      dataIndex: 'product_id',
      valueType: 'select',
      ellipsis: true,
      fieldProps: {
        options: productOptions.filter((option) => option.value !== ''),
        showSearch: true,
        filterOption: false,
        onSearch: setProductSearch,
        loading: productsLoading,
      },
      render: (_, record) => record.product?.name || '全站商品通用',
    },
    {
      title: '已用 / 总额度',
      dataIndex: 'used_count',
      search: false,
      width: 120,
      align: 'right',
      render: (_, record) => `${record.used_count || 0} / ${record.max_uses || '不限'}`,
    },
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
      title: '截止有效期',
      dataIndex: 'expires_at',
      search: false,
      width: 165,
      render: (_, record) => (record.expires_at ? formatDateMinute(record.expires_at) : '长期有效'),
    },
    {
      title: '操作',
      valueType: 'option',
      width: 130,
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
                  setProductSearch('');
                  setModalVisible(true);
                }}
              >
                编辑
              </Button>,
              <Popconfirm
                key="delete"
                title="确认删除该优惠券？"
                onConfirm={async () => {
                  try {
                    await deleteCoupon(record.id);
                    message.success('优惠券已删除');
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

  return (
    <>
      <ProTable
        actionRef={actionRef}
        rowKey="id"
        columns={columns}
        scroll={{ x: 'max-content' }}
        form={{ name: 'coupons-search' }}
        search={{ labelWidth: 'auto' }}
        request={async (params) => {
          const res = await getCoupons({ page: params.current, per_page: params.pageSize, ...params });
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
                    setProductSearch('');
                    setModalVisible(true);
                  }}
                >
                  新增优惠券
                </Button>,
              ]
            : []
        }
      />
      <ModalForm
        name="coupon-editor"
        key={editingRecord?.id || 'new'}
        title={editingRecord ? '编辑优惠券规则' : '创建优惠券'}
        open={modalVisible}
        onOpenChange={setModalVisible}
        initialValues={editingRecord || { type: 'fixed', is_active: true }}
        modalProps={{ destroyOnHidden: true }}
        onFinish={async (values) => {
          try {
            const data = {
              ...values,
              product_id: values.product_id || null,
              expires_at: values.expires_at ?? null,
              starts_at: values.starts_at ?? null,
              min_amount: values.min_amount ?? 0,
              max_uses: values.max_uses ?? 0,
            };
            if (editingRecord) {
              await updateCoupon(editingRecord.id, data);
              message.success('优惠券规则已更新');
            } else {
              await createCoupon(data);
              message.success('优惠券已创建');
            }
            actionRef.current?.reload();
            return true;
          } catch (err) {
            message.error(err.response?.data?.message || '保存失败');
            return false;
          }
        }}
      >
        <div className="admin-form-grid-2">
          <ProFormText name="code" label="优惠券码" placeholder="留空自动生成随机码；编辑时留空保留原码" />
          <ProFormSelect
            name="product_id"
            label="适用商品范围"
            options={productOptions}
            fieldProps={{
              showSearch: true,
              filterOption: false,
              onSearch: setProductSearch,
              loading: productsLoading,
            }}
            placeholder="搜索指定商品；留空则全站通用"
          />
        </div>
        <div className="admin-form-grid-2">
          <ProFormSelect
            name="type"
            label="折扣计算方式"
            options={[
              { label: '固定金额立减（CNY）', value: 'fixed' },
              { label: '按订单比例减免（%）', value: 'percent' },
            ]}
            rules={[{ required: true, message: '请选择折扣计算方式' }]}
          />
          <ProFormDigit
            name="value"
            label="优惠数值"
            min={0.01}
            max={99999999.99}
            fieldProps={{ precision: 2 }}
            rules={[{ required: true, message: '请输入优惠数值' }]}
            extra="固定立减填写人民币金额；比例减免填写百分比数值（如 10 代表减免 10%）"
          />
        </div>
        <div className="admin-form-grid-2">
          <ProFormDigit
            name="min_amount"
            label="最低订单门槛（CNY）"
            min={0}
            max={99999999.99}
            fieldProps={{ precision: 2 }}
            placeholder="留空或填 0 表示无金额门槛"
          />
          <ProFormDigit
            name="max_uses"
            label="累计核销次数上限"
            min={0}
            max={2147483647}
            fieldProps={{ precision: 0 }}
            placeholder="留空或填 0 表示不限次数"
          />
        </div>
        <div className="admin-form-grid-2">
          <ProFormDateTimePicker name="starts_at" label="生效起始时间" placeholder="留空表示创建后立即生效" />
          <ProFormDateTimePicker name="expires_at" label="失效截止时间" placeholder="留空表示长期有效" />
        </div>
        <ProFormSwitch name="is_active" label="启用状态" />
      </ModalForm>
    </>
  );
}
