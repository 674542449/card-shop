import React, { useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import Link from '../components/PermissionLink';
import { ProTable } from '@ant-design/pro-components';
import { Tag, Button, message, Space } from 'antd';
import { ExportOutlined } from '@ant-design/icons';
import { getOrders, exportOrders } from '../services/api';
import { initializationStates } from '../utils/paymentInitialization';
import {
  ORDER_STATUS_MAP,
  ORDER_STATUS_ENUM,
  PAYMENT_METHOD_MAP,
  PAYMENT_METHOD_ENUM,
  formatDateMinute,
  toTablePage,
  downloadBlob,
} from '../utils/orderMeta';

export default function Orders() {
  const actionRef = useRef();
  const filtersRef = useRef({});
  const [exporting, setExporting] = useState(false);
  const [searchParams] = useSearchParams();
  const requestedStatus = searchParams.get('status');
  const initialStatus = Object.prototype.hasOwnProperty.call(ORDER_STATUS_MAP, requestedStatus)
    ? requestedStatus
    : undefined;
  const paymentReviewOnly = searchParams.get('payment_review') === '1';
  const requestedInitialization = searchParams.get('payment_initialization');
  const initialInitialization =
    Object.prototype.hasOwnProperty.call(initializationStates, requestedInitialization) ||
    requestedInitialization === 'none'
      ? requestedInitialization
      : undefined;

  const handleExport = async () => {
    if (exporting) return;
    setExporting(true);
    try {
      const res = await exportOrders(filtersRef.current);
      downloadBlob(res.data, 'orders.csv', 'text/csv;charset=utf-8');
    } catch (err) {
      message.error(err.response?.data?.message || '订单导出失败，请稍后重试');
    } finally {
      setExporting(false);
    }
  };

  const columns = [
    {
      title: '付款待核对',
      dataIndex: 'payment_review',
      hideInTable: true,
      valueType: 'select',
      valueEnum: { 1: { text: '仅待核对订单' }, 0: { text: '全部订单' } },
    },
    {
      title: '订单号',
      dataIndex: 'order_no',
      copyable: true,
      width: 215,
      render: (_, record) => <span className="admin-order-number">{record.order_no}</span>,
    },
    {
      title: '商品名称',
      dataIndex: 'product_name',
      search: false,
      ellipsis: true,
      width: 200,
      render: (_, record) => record.product_name || record.product?.name || '-',
    },
    { title: '联系邮箱', dataIndex: 'email', width: 200, ellipsis: true },
    { title: '数量', dataIndex: 'quantity', search: false, width: 68, align: 'right' },
    {
      title: '实付金额',
      dataIndex: 'total_amount',
      sorter: true,
      search: false,
      width: 110,
      align: 'right',
      render: (_, record) => <span className="admin-amount">¥{record.total_amount}</span>,
    },
    {
      title: '支付方式',
      dataIndex: 'payment_method',
      width: 125,
      valueType: 'select',
      valueEnum: PAYMENT_METHOD_ENUM,
      render: (_, record) => PAYMENT_METHOD_MAP[record.payment_method] || record.payment_method || '-',
    },
    {
      title: '收银台状态',
      dataIndex: 'payment_initialization',
      valueType: 'select',
      width: 145,
      valueEnum: {
        ...Object.fromEntries(Object.entries(initializationStates).map(([key, value]) => [key, { text: value.text }])),
        none: { text: '暂无创建记录' },
      },
      render: (_, record) => {
        const state = initializationStates[record.payment_initialization];
        return state ? <Tag color={state.color}>{state.text}</Tag> : '-';
      },
    },
    {
      title: '订单状态',
      dataIndex: 'status',
      width: 145,
      valueType: 'select',
      valueEnum: ORDER_STATUS_ENUM,
      render: (_, record) => {
        const s = ORDER_STATUS_MAP[record.status];
        return (
          <Space size={4} wrap>
            {s ? <Tag color={s.color}>{s.text}</Tag> : record.status}
            {record.has_payment_review && <Tag color="orange">待核对</Tag>}
          </Space>
        );
      },
    },
    {
      title: '下单时间',
      dataIndex: 'created_at',
      sorter: true,
      valueType: 'dateRange',
      width: 165,
      render: (_, record) => formatDateMinute(record.created_at),
      search: {
        transform: (value) => ({
          start_date: value[0],
          end_date: value[1],
        }),
      },
    },
    {
      title: '操作',
      valueType: 'option',
      width: 80,
      fixed: 'right',
      render: (_, record) => [
        <Link key="detail" to={`/orders/${record.id}`}>
          详情
        </Link>,
      ],
    },
  ];

  return (
    <ProTable
      key={`orders-${initialStatus || 'all'}-${paymentReviewOnly}-${initialInitialization || 'all'}`}
      actionRef={actionRef}
      rowKey="id"
      columns={columns}
      scroll={{ x: 1320 }}
      search={{ labelWidth: 'auto' }}
      form={{
        name: 'orders-search',
        initialValues: {
          status: initialStatus,
          payment_review: paymentReviewOnly ? '1' : undefined,
          payment_initialization: initialInitialization,
        },
      }}
      request={async (params, sort = {}) => {
        const { current, pageSize, ...rest } = params;
        filtersRef.current = rest;
        const [sortBy, sortOrder] = Object.entries(sort)[0] || [];
        const res = await getOrders({
          page: current,
          per_page: pageSize,
          ...rest,
          ...(sortBy ? { sort: sortBy, dir: sortOrder === 'ascend' ? 'asc' : 'desc' } : {}),
        });
        return toTablePage(res);
      }}
      toolBarRender={() => [
        <Button key="export" icon={<ExportOutlined />} loading={exporting} onClick={handleExport}>
          导出 CSV
        </Button>,
      ]}
    />
  );
}
