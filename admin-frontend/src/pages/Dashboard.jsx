import React, { useEffect, useState } from 'react';
import Link from '../components/PermissionLink';
import { Alert, Button, Card, Col, Row, Skeleton, Table, Tag, Typography, Empty, Space } from 'antd';
import {
  ArrowRightOutlined,
  ClockCircleOutlined,
  FileTextOutlined,
  ReloadOutlined,
  ShoppingOutlined,
  WalletOutlined,
  BellOutlined,
} from '@ant-design/icons';
import { getDashboard } from '../services/api';
import { useOutletContext } from 'react-router-dom';
import { allows } from '../permissions';
import { ORDER_STATUS_MAP, formatMoney } from '../utils/orderMeta';

function Stat({ label, value, tone, hint, to, icon }) {
  const body = (
    <Card className={`admin-stat ${tone ? `admin-stat-${tone}` : ''}`} size="small">
      <div className="admin-stat-top">
        <span>{label}</span>
        <span className="admin-stat-icon">{icon}</span>
      </div>
      <div className="admin-stat-value">{value}</div>
      <div className="admin-stat-hint">
        <span>{hint}</span>
        {to && <ArrowRightOutlined />}
      </div>
    </Card>
  );
  return to ? <Link to={to} className="dash-tile-link">{body}</Link> : body;
}

/** Daily paid revenue bar chart; zero-revenue days remain visible on the baseline. */
function RevenueBars({ labels = [], data = [] }) {
  const values = data.map((value) => Number(value) || 0);
  const max = Math.max(...values, 0);
  if (!values.length || max === 0) {
    return (
      <div className="admin-chart-empty">
        <Empty description="近 7 日暂无已支付交易数据" image={Empty.PRESENTED_IMAGE_SIMPLE} />
      </div>
    );
  }
  const best = values.indexOf(max);
  const summary = labels.map((label, index) => `${label} ${formatMoney(values[index])}`).join('，');
  return (
    <div className="admin-revenue-chart" role="img" aria-label={`近 7 日销售额趋势：${summary}`}>
      <div className="admin-chart-bars">
        {values.map((value, index) => (
          <div className="admin-chart-column" key={index}>
            {index === best && <span className="admin-chart-best">{formatMoney(value)}</span>}
            <div
              title={`${labels[index] || ''}　${formatMoney(value)}`}
              className={`dash-bar ${index === best ? 'dash-bar-best' : ''} ${value === 0 ? 'dash-bar-zero' : ''}`}
              style={{ height: Math.max(4, Math.round((value / max) * 134)) }}
            />
          </div>
        ))}
      </div>
      <div className="admin-chart-labels">
        {labels.map((label, index) => <span key={index}>{label}</span>)}
      </div>
      <div className="admin-chart-caption">
        <span className="admin-chart-key" />
        <span>已支付订单销售额（未扣减退款）</span>
        <span>按订单支付完成日期统计</span>
      </div>
    </div>
  );
}

export default function Dashboard() {
  const admin = useOutletContext();
  const [loading, setLoading] = useState(true);
  const [data, setData] = useState({});
  const [failed, setFailed] = useState(false);

  const load = () => {
    setLoading(true);
    setFailed(false);
    getDashboard()
      .then((res) => setData(res.data?.data || res.data))
      .catch(() => setFailed(true))
      .finally(() => setLoading(false));
  };
  useEffect(load, []);

  if (loading) return <Skeleton active paragraph={{ rows: 8 }} />;
  if (failed) {
    return (
      <Alert
        type="error"
        showIcon
        message="经营数据加载失败"
        description="暂时无法获取实时经营统计数据，请检查网络连接或后端服务状态后重试。"
        action={<Button size="small" onClick={load}>重试</Button>}
      />
    );
  }

  const pending = Number(data.pending_orders || 0);
  const hasLowStock = Number(data.low_stock_count) > 0;
  const dateLabel = new Date().toLocaleDateString('zh-CN', {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
    weekday: 'long',
  });

  const columns = [
    {
      title: '订单号',
      dataIndex: 'order_no',
      width: 220,
      render: (value, record) => <Link className="admin-order-number" to={`/orders/${record.id}`}>{value}</Link>,
    },
    { title: '商品名称', dataIndex: 'product_name', ellipsis: true, width: 220 },
    {
      title: '交易金额',
      dataIndex: 'total_amount',
      width: 120,
      align: 'right',
      render: (value) => <span className="admin-amount">{formatMoney(value)}</span>,
    },
    {
      title: '订单状态',
      dataIndex: 'status',
      width: 145,
      render: (value, record) => (
        <Space size={4} wrap>
          <Tag color={ORDER_STATUS_MAP[value]?.color}>{ORDER_STATUS_MAP[value]?.text || value}</Tag>
          {record.has_payment_review && <Tag color="orange">待核对</Tag>}
        </Space>
      ),
    },
    { title: '下单时间', dataIndex: 'created_at', width: 165 },
  ];

  return (
    <div className="admin-dashboard">
      <div className="admin-dashboard-toolbar">
        <div className="admin-dashboard-meta">
          <span className="admin-dashboard-date">{dateLabel}</span>
          <span className="admin-dashboard-divider">|</span>
          <span>金额指标最多缓存 15 秒；待办工单与最近订单实时查询</span>
        </div>
        <Button icon={<ReloadOutlined />} onClick={load}>刷新数据</Button>
      </div>

      <Card title="待处理事项" className="admin-todo-card">
        <Row gutter={[16, 16]}>
          {allows(admin, 'orders') && (
            <Col xs={24} sm={12} xl={6}>
              <Stat
                label="付款待核对"
                value={data.payment_review_orders || 0}
                tone={data.payment_review_orders > 0 ? 'attention' : undefined}
                hint="核对异常回执与交付状态"
                to="/orders?payment_review=1"
                icon={<WalletOutlined />}
              />
            </Col>
          )}
          {allows(admin, 'refunds') && (
            <Col xs={24} sm={12} xl={6}>
              <Stat
                label="售后待审核（退款）"
                value={data.requested_refunds || 0}
                tone={data.requested_refunds > 0 ? 'attention' : undefined}
                hint="审核买家提交的退款申请"
                to="/refunds?status=requested"
                icon={<ClockCircleOutlined />}
              />
              <Link className="admin-sub-link" to="/refunds?status=approved">
                已批准待打款：{data.approved_refunds || 0} 笔 →
              </Link>
            </Col>
          )}
          {allows(admin, 'notifications') && (
            <Col xs={24} sm={12} xl={6}>
              <Stat
                label="通知发送失败"
                value={data.failed_notifications || 0}
                tone={data.failed_notifications > 0 ? 'attention' : undefined}
                hint="排查通道配置并重新入队"
                to="/tasks?tab=notifications&status=failed"
                icon={<BellOutlined />}
              />
            </Col>
          )}
          {allows(admin, 'catalog') && (
            <Col xs={24} sm={12} xl={6}>
              <Stat
                label="低库存商品"
                value={data.low_stock_count || 0}
                tone={data.low_stock_count > 0 ? 'attention' : undefined}
                hint="及时补充可售卡密库存"
                to="/products?low_stock=1"
                icon={<ShoppingOutlined />}
              />
            </Col>
          )}
        </Row>
        {!['orders', 'refunds', 'notifications', 'catalog'].some((area) => allows(admin, area)) && (
          <Typography.Text type="secondary">当前账户暂无待办业务模块的访问权限。</Typography.Text>
        )}
      </Card>

      <Row gutter={[16, 16]} className="admin-stat-row">
        <Col xs={12} xl={6}>
          <Stat
            label="今日销售额"
            value={formatMoney(data.today_revenue)}
            tone="money"
            hint={`本月累计 ${formatMoney(data.month_revenue)}`}
            icon={<WalletOutlined />}
          />
        </Col>
        <Col xs={12} xl={6}>
          <Stat
            label="今日已付订单"
            value={data.today_orders ?? 0}
            hint={`历史累计 ${data.total_orders ?? 0} 笔`}
            icon={<FileTextOutlined />}
          />
        </Col>
        <Col xs={12} xl={6}>
          <Stat
            label="今日净销售额"
            value={formatMoney(data.today_net_revenue)}
            hint={`本月净额 ${formatMoney(data.month_net_revenue)}`}
            icon={<WalletOutlined />}
          />
        </Col>
        <Col xs={12} xl={6}>
          <Stat
            label="在售商品数"
            value={data.total_products ?? 0}
            hint="查看已上架商品目录"
            to="/products?is_active=1"
            icon={<ShoppingOutlined />}
          />
        </Col>
      </Row>

      <div className="admin-metrics-strip">
        <div className="admin-metrics-strip-items">
          <span>今日已完成退款：<strong>{formatMoney(data.today_refund_amount)}</strong></span>
          <span>累计退款总额：<strong>{formatMoney(data.total_refund_amount)}</strong></span>
          <span>
            锁库等待支付：<Link to="/orders?status=pending"><strong>{pending} 笔订单</strong></Link>（超时未付自动释放库存）
          </span>
        </div>
        <span className="admin-metrics-note">
          统计口径：销售额按付款时间统计，退款按实际完成时间统计；净销售额扣减订单原款退回，退款总额另含重复收款退回。
        </span>
      </div>

      <Row gutter={[18, 18]}>
        <Col xs={24} lg={hasLowStock ? 15 : 24}>
          <Card className="admin-chart-card" title="近 7 日销售额趋势" extra={<span className="admin-card-caption">单位：CNY</span>}>
            <RevenueBars labels={data.chart_labels} data={data.chart_data} />
          </Card>
        </Col>
        {hasLowStock && (
          <Col xs={24} lg={9}>
            <Card title="库存预警明细" style={{ height: '100%' }} extra={<Link to="/products?low_stock=1">全部预警商品</Link>}>
              <Table
                rowKey="id"
                size="small"
                pagination={false}
                dataSource={data.low_stock_products || []}
                scroll={{ x: 420 }}
                columns={[
                  {
                    title: '商品名称',
                    dataIndex: 'name',
                    ellipsis: true,
                    render: (value, record) => <Link to={`/products/${record.id}/cards`}>{value}</Link>,
                  },
                  { title: '可售库存', dataIndex: 'stock_count', width: 90, align: 'right' },
                  { title: '预警阈值', dataIndex: 'low_stock_threshold', width: 90, align: 'right' },
                ]}
              />
            </Card>
          </Col>
        )}
      </Row>

      <Card
        className="admin-recent-orders"
        title="最近交易订单"
        extra={<Link to="/orders" className="admin-card-more">查看全部订单 <ArrowRightOutlined /></Link>}
      >
        <Table
          rowKey="id"
          size="small"
          columns={columns}
          dataSource={data.recent_orders || []}
          pagination={false}
          scroll={{ x: 820 }}
          locale={{ emptyText: <Empty description="暂无订单记录" image={Empty.PRESENTED_IMAGE_SIMPLE} /> }}
        />
      </Card>
    </div>
  );
}
