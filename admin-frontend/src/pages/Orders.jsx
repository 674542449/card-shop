import React, { useRef } from 'react';
import { useNavigate } from 'react-router-dom';
import { ProTable } from '@ant-design/pro-components';
import { Tag, Button, message } from 'antd';
import { ExportOutlined } from '@ant-design/icons';
import dayjs from 'dayjs';
import { getOrders, exportOrders } from '../services/api';

// Laravel serialises timestamps to UTC ISO strings. Rendering them raw showed the
// operator a time 8 hours behind the app's Asia/Shanghai clock; dayjs converts to the
// viewer's local zone.
const fmt = (v) => (v ? dayjs(v).format('YYYY-MM-DD HH:mm') : '-');

const statusMap = {
  pending: { text: '待支付', color: 'orange' },
  paid: { text: '已支付', color: 'green' },
  closed: { text: '已关闭', color: 'default' },
  expired: { text: '已过期', color: 'red' },
};

// Must match the values the backend actually stores: CreateOrderRequest allows the
// three usdt_* variants, and OrderController::markPaid writes 'manual'. The old map
// had a bare 'usdt' that is never stored and was missing 'manual' entirely, so those
// orders showed a raw enum value.
const paymentMethodMap = {
  alipay: '支付宝',
  wechat: '微信支付',
  usdt_trc20: 'USDT (TRC20)',
  usdt_bep20: 'USDT (BEP20)',
  usdt_polygon: 'USDT (Polygon)',
  manual: '人工确认',
};

export default function Orders() {
  const actionRef = useRef();
  // The filter set behind the rows currently displayed.
  const filtersRef = useRef({});
  const navigate = useNavigate();

  const handleExport = async () => {
    try {
      const res = await exportOrders(filtersRef.current);
      // The endpoint returns CSV, not a spreadsheet; the .xlsx name made Excel warn
      // about a corrupt file on every export.
      const url = window.URL.createObjectURL(new Blob([res.data], { type: 'text/csv;charset=utf-8' }));
      const link = document.createElement('a');
      link.href = url;
      link.setAttribute('download', 'orders.csv');
      document.body.appendChild(link);
      link.click();
      link.remove();
      window.URL.revokeObjectURL(url);
    } catch {
      message.error('导出失败');
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
      render: (_, record) => `¥${record.total_amount}`,
    },
    {
      title: '支付方式',
      dataIndex: 'payment_method',
      width: 100,
      valueType: 'select',
      valueEnum: Object.fromEntries(
        Object.entries(paymentMethodMap).map(([value, text]) => [value, { text }])
      ),
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
      render: (_, record) => fmt(record.created_at),
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
        // 路径不带 /admin 前缀：Router 的 basename 已经是 ADMIN_BASE，这里再写一次会
        // 拼成 {basename}/admin/orders/5，匹配不到任何路由，落到 <Route path="*"> 被
        // 重定向回概览页 —— 表现就是「点查看没反应，跳回首页」。
        // 同一个目的地在 Dashboard.jsx 的最近订单里用的就是这种写法。
        <a key="detail" onClick={() => navigate(`/orders/${record.id}`)}>
          查看
        </a>,
      ],
    },
  ];

  return (
    <ProTable
      actionRef={actionRef}
      rowKey="id"
      columns={columns}
      search={{ labelWidth: 'auto' }}
      request={async (params) => {
        const { current, pageSize, ...rest } = params;
        // Keep the active filters so 导出订单 can export what is on screen. Without
        // this it posted {} and always downloaded every order in the shop, which
        // looks identical to a working export until you open the file.
        filtersRef.current = rest;
        const res = await getOrders({ page: current, per_page: pageSize, ...rest });
        const body = res.data ?? {};
        const list = Array.isArray(body) ? body : (body.data ?? []);
        return {
          data: list,
          total: Array.isArray(body) ? list.length : (body.total ?? list.length),
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
