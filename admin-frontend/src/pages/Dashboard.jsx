import React, { useEffect, useState } from 'react';
import Link from '../components/PermissionLink';
import { Alert, Button, Card, Col, Row, Skeleton, Table, Tag, Typography, Empty, Space } from 'antd';
import { ArrowRightOutlined, ClockCircleOutlined, FileTextOutlined, ReloadOutlined, ShoppingOutlined, WalletOutlined, PlusOutlined } from '@ant-design/icons';
import { getDashboard } from '../services/api';

const STATUS = {
  pending: { text: '待支付', color: 'gold' },
  paid: { text: '已支付', color: 'green' },
  expired: { text: '已过期', color: 'default' },
  closed: { text: '已关闭', color: 'red' },
};
const money = (value) => '¥' + Number(value || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function Stat({ label, value, tone, hint, to, icon }) {
  const body = (
    <Card className={`admin-stat ${tone ? `admin-stat-${tone}` : ''}`} size="small">
      <div className="admin-stat-top"><span>{label}</span><span className="admin-stat-icon">{icon}</span></div>
      <div className="admin-stat-value">{value}</div>
      <div className="admin-stat-hint">{hint}{to && <ArrowRightOutlined />}</div>
    </Card>
  );
  return to ? <Link to={to} className="dash-tile-link">{body}</Link> : body;
}

/** Daily totals are separate buckets; zero revenue remains visible on the baseline. */
function RevenueBars({ labels = [], data = [] }) {
  const values = data.map((value) => Number(value) || 0);
  const max = Math.max(...values, 0);
  if (!values.length || max === 0) {
    return <div className="admin-chart-empty"><Empty description="最近 7 天还没有收入" image={Empty.PRESENTED_IMAGE_SIMPLE} /></div>;
  }
  const best = values.indexOf(max);
  const summary = labels.map((label, index) => label + ' ' + money(values[index])).join('，');
  return (
    <div className="admin-revenue-chart" role="img" aria-label={'近 7 天每日销售额：' + summary}>
      <div className="admin-chart-bars">
        {values.map((value, index) => (
          <div className="admin-chart-column" key={index}>
            {index === best && <span className="admin-chart-best">{money(value)}</span>}
            <div
              title={`${labels[index] || ''}　${money(value)}`}
              className={`dash-bar ${index === best ? 'dash-bar-best' : ''} ${value === 0 ? 'dash-bar-zero' : ''}`}
              style={{ height: Math.max(3, Math.round((value / max) * 130)) }}
            />
          </div>
        ))}
      </div>
      <div className="admin-chart-labels">{labels.map((label, index) => <span key={index}>{label}</span>)}</div>
      <div className="admin-chart-caption"><span className="admin-chart-key" />已支付订单销售额（未扣退款）<span>按成交日期统计</span></div>
    </div>
  );
}

export default function Dashboard() {
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
    return <Alert type="error" showIcon message="数据加载失败" description="没能取到今天的经营数据。这不表示没有成交，请检查网络或服务器后重试。" action={<Button size="small" onClick={load}>重试</Button>} />;
  }

  const pending = Number(data.pending_orders || 0);
  const dateLabel = new Date().toLocaleDateString('zh-CN', { month: 'long', day: 'numeric', weekday: 'long' });
  const columns = [
    {
      title: '订单号', dataIndex: 'order_no', width: 225,
      render: (value, record) => <Link className="admin-order-number" to={'/orders/' + record.id}>{value}</Link>,
    },
    { title: '商品', dataIndex: 'product_name', ellipsis: true, width: 220 },
    { title: '金额', dataIndex: 'total_amount', width: 120, align: 'right', render: (value) => <span className="admin-amount">{money(value)}</span> },
    { title: '状态', dataIndex: 'status', width: 140, render: (value, record) => <Space size={4} wrap><Tag color={STATUS[value]?.color}>{STATUS[value]?.text || value}</Tag>{record.has_payment_review && <Tag color="orange">待核对</Tag>}</Space> },
    { title: '时间', dataIndex: 'created_at', width: 155 },
  ];

  return (
    <div className="admin-dashboard">
      <section className="admin-dashboard-intro">
        <div><span className="admin-eyebrow">{dateLabel}</span><h2>每一笔订单，都井然有序。</h2><p>看看今天的经营进展，再照顾好接下来要做的事。</p></div>
        <Button icon={<ReloadOutlined />} onClick={load}>刷新数据</Button>
      </section>
      <Row gutter={[16, 16]} className="admin-stat-row">
        <Col xs={12} xl={6}><Stat label="今日销售额" value={money(data.today_revenue)} tone="money" hint={'本月 ' + money(data.month_revenue)} icon={<WalletOutlined />} /></Col>
        <Col xs={12} xl={6}><Stat label="今日订单" value={data.today_orders ?? 0} hint={'累计 ' + (data.total_orders ?? 0) + ' 笔'} icon={<FileTextOutlined />} /></Col>
        <Col xs={12} xl={6}><Stat label="待支付订单" value={pending} tone={pending > 0 ? 'attention' : undefined} hint={pending > 0 ? '查看待付款的订单' : '没有待处理的订单'} to={pending > 0 ? '/orders?status=pending' : undefined} icon={<ClockCircleOutlined />} /></Col>
        <Col xs={12} xl={6}><Stat label="在售商品" value={data.total_products ?? 0} hint="查看已上架的商品" to="/products?is_active=1" icon={<ShoppingOutlined />} /></Col>
      </Row>
      <Row gutter={[16, 16]} className="admin-stat-row">
        <Col xs={12} xl={8}><Stat label="今日净销售额" value={money(data.today_net_revenue)} hint={'本月 ' + money(data.month_net_revenue)} icon={<WalletOutlined />} /></Col>
        <Col xs={12} xl={8}><Stat label="今日完成退款" value={money(data.today_refund_amount)} hint={'累计 ' + money(data.total_refund_amount)} icon={<WalletOutlined />} /></Col>
        <Col xs={12} xl={8}><Stat label="退款待审核" value={data.requested_refunds || 0} hint={'已批准待退款 ' + (data.approved_refunds || 0) + ' 笔'} to="/refunds?status=requested" icon={<ClockCircleOutlined />} /></Col>
      </Row>
      <Typography.Paragraph type="secondary">销售额按订单付款日期统计；退款按实际完成日期统计。净销售额只扣订单退款，退款金额另含额外收款退回；这些数据不代表利润。金额统计最多缓存 15 秒，待处理任务和最近订单实时读取。</Typography.Paragraph>
      <Row gutter={[20, 20]}>
        <Col xs={24}>
          <Space wrap size="large">
            <Link to="/orders?payment_review=1">付款待核对：{data.payment_review_orders || 0} 笔</Link>
            <Link to="/products?low_stock=1">低库存商品：{data.low_stock_count || 0} 件</Link>
            <Link to="/notifications?status=failed">发送失败：{data.failed_notifications || 0} 条</Link>
          </Space>
        </Col>
        {Number(data.low_stock_count) > 0 && <Col xs={24}>
          <Card title="库存预警" extra={<Link to="/products?low_stock=1">查看全部</Link>}>
            <Table rowKey="id" size="small" pagination={false} dataSource={data.low_stock_products || []} scroll={{ x: 500 }} columns={[
              { title: '商品', dataIndex: 'name', render: (value, record) => <Link to={`/products/${record.id}/cards`}>{value}</Link> },
              { title: '可售库存', dataIndex: 'stock_count' }, { title: '预警阈值', dataIndex: 'low_stock_threshold' },
            ]} />
          </Card>
        </Col>}
        <Col xs={24} lg={16}>
          <Card className="admin-chart-card" title="近 7 天销售额（未扣退款）" extra={<span className="admin-card-caption">经营趋势</span>}>
            <RevenueBars labels={data.chart_labels} data={data.chart_data} />
          </Card>
        </Col>
        <Col xs={24} lg={8}>
          <Card className="admin-shortcuts-card" title="常用操作" extra={<PlusOutlined className="admin-card-caption" />}>
            <Link to="/products" className="admin-shortcut"><span className="admin-shortcut-icon"><ShoppingOutlined /></span><span><strong>打理商品</strong><small>上架、定价与库存补充</small></span><ArrowRightOutlined /></Link>
            <Link to="/orders" className="admin-shortcut"><span className="admin-shortcut-icon"><FileTextOutlined /></span><span><strong>查看订单</strong><small>确认付款，管理交付</small></span><ArrowRightOutlined /></Link>
            <Link to="/articles" className="admin-shortcut"><span className="admin-shortcut-icon"><PlusOutlined /></span><span><strong>更新公告</strong><small>发布教程与商城消息</small></span><ArrowRightOutlined /></Link>
          </Card>
        </Col>
      </Row>
      <Card className="admin-recent-orders" title="最近订单" extra={<Link to="/orders" className="admin-card-more">全部订单 <ArrowRightOutlined /></Link>}>
        <Table rowKey="id" size="small" columns={columns} dataSource={data.recent_orders || []} pagination={false} scroll={{ x: 820 }} locale={{ emptyText: <Empty description="还没有订单" image={Empty.PRESENTED_IMAGE_SIMPLE} /> }} />
      </Card>
    </div>
  );
}
