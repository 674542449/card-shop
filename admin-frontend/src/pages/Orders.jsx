import React, { useRef } from 'react';
import { useNavigate } from 'react-router-dom';
import { ProTable } from '@ant-design/pro-components';
import { Tag, Button } from 'antd';
import { ExportOutlined } from '@ant-design/icons';
import { getOrders, exportOrders } from '../services/api';

const statusMap = {
  pending: { text: '待支付', color: 'orange' },
  paid: { text: '已支付', color: 'green' },
  closed: { text: '已关闭', color: 'default' },
  expired: { text: '已过期', color: 'red' },
};

const paymentMethodMap = {
  alipay: '支付宝',
  wechat: '微信',
  usdt: 'USDT',
};

export default function Orders() {
  const actionRef = useRef();
  const navigate = useNavigate();

  const handleExport = async () => {
    try {
      const res = await exportOrders({});
      const url = window.URL.createObjectURL(new Blob([res.data]));
      const link = document.createElement('a');
      link.href = url;
      link.setAttribute('download', 'orders.xlsx');
      document.body.appendChild(link);
      link.click();
      link.remove();
    } catch {
      // ignore
    }
  };

  const columns = [
    { title: '订单号', dataIndex: 'order_no', copyable: true, width: 200 },
    {
      title: '商品',
      dataIndex: 'product_name',
      search: false,
      render: (_, record) => record.product?.name || '-',
    },
    { title: '邮箱', dataIndex: 'email', width: 200 },
    { title: '数量', dataIndex: 'quantity', search: false, width: 60 },
    {
      title: '总金额',
      dataIndex: 'total_amount',
      search: false,
      width: 100,
      render: (val) => `¥${val}`,
    },
    {
      title: '支付方式',
      dataIndex: 'payment_method',
      width: 100,
      valueType: 'select',
      valueEnum: {
        alipay: { text: '支付宝' },
        wechat: { text: '微信' },
        usdt: { text: 'USDT' },
      },
      render: (_, record) => paymentMethodMap[record.payment_method] || record.payment_method || '-',
    },
    {
      title: '状态',
      dataIndex: 'status',
      width: 100,
      valueType: 'select',
      valueEnum: {
        pending: { text: '待支付' },
        paid: { text: '已支付' },
        closed: { text: '已关闭' },
        expired: { text: '已过期' },
      },
      render: (_, record) => {
        const s = statusMap[record.status];
        return s ? <Tag color={s.color}>{s.text}</Tag> : record.status;
      },
    },
    {
      title: '创建时间',
      dataIndex: 'created_at',
      valueType: 'dateRange',
      width: 180,
      render: (_, record) => record.created_at,
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
      render: (_, record) => [
        <a key="detail" onClick={() => navigate(`/admin/orders/${record.id}`)}>
          查看
        </a>,
      ],
    },
  ];

  return (
    <ProTable
      headerTitle="订单管理"
      actionRef={actionRef}
      rowKey="id"
      columns={columns}
      search={{ labelWidth: 'auto' }}
      request={async (params) => {
        const { current, pageSize, ...rest } = params;
        const res = await getOrders({ page: current, per_page: pageSize, ...rest });
        const d = res.data?.data || res.data;
        return {
          data: d.data || d,
          total: d.total || d.length,
          success: true,
        };
      }}
      toolBarRender={() => [
        <Button key="export" icon={<ExportOutlined />} onClick={handleExport}>
          导出订单
        </Button>,
      ]}
    />
  );
}
