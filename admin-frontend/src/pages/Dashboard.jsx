import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Card, Col, Row, Skeleton, Table, Tag, Typography, Empty } from 'antd';
import { getDashboard } from '../services/api';

const STATUS = {
  pending: { text: '待支付', color: 'gold' },
  paid: { text: '已支付', color: 'green' },
  expired: { text: '已过期', color: 'default' },
  closed: { text: '已关闭', color: 'red' },
};

const money = (v) =>
  '¥' + Number(v || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/**
 * A stat tile, not a chart: a single number's job is to be read, not compared.
 *
 * `tone` is reserved for the one tile that can require action. Amber is the shop's
 * own --warm-btn and is used here for nothing else, so a coloured tile on this page
 * always means the same thing — something is waiting on you.
 */
function Stat({ label, value, tone, hint, to }) {
  const body = (
    <Card
      size="small"
      styles={{ body: { padding: '14px 16px' } }}
      style={{
        height: '100%',
        borderColor: tone === 'attention' ? '#ffb800' : undefined,
        background: tone === 'attention' ? '#fffbf0' : undefined,
      }}
    >
      <Typography.Text type="secondary" style={{ fontSize: 13 }}>
        {label}
      </Typography.Text>
      <div
        style={{
          /* Tabular figures so digits keep their column between tiles instead of
             shifting width with the value — this row is meant to be read at a glance. */
          fontVariantNumeric: 'tabular-nums',
          fontSize: 26,
          fontWeight: 700,
          lineHeight: 1.25,
          marginTop: 2,
          color: tone === 'money' ? '#ff4400' : tone === 'attention' ? '#a86a00' : '#20242c',
        }}
      >
        {value}
      </div>
      {hint ? (
        <Typography.Text type="secondary" style={{ fontSize: 12 }}>
          {hint}
        </Typography.Text>
      ) : null}
    </Card>
  );

  return to ? (
    <Link to={to} className="dash-tile-link" style={{ display: 'block', height: '100%' }}>
      {body}
    </Link>
  ) : (
    body
  );
}

/**
 * Seven daily revenue totals as bars.
 *
 * Bars, not a line: these are seven discrete daily buckets, and a line between them
 * would claim a continuity the data does not have. One series, so there is no legend
 * — the card title names it — and only the best day carries a number, because a
 * label on every bar is noise at this size.
 *
 * #009688 is the storefront's own --teal, and it is the shade that passes the palette
 * checks (lightness band, chroma floor, >=3:1 against the card surface). The darker
 * #00796b used for buttons measured below the chroma floor and read as grey — the
 * button needed contrast for white text, this needs chroma to read as data.
 */
function RevenueBars({ labels = [], data = [] }) {
  const values = data.map((v) => Number(v) || 0);
  const max = Math.max(...values, 0);

  if (!values.length || max === 0) {
    return <Empty description="最近 7 天还没有收入" image={Empty.PRESENTED_IMAGE_SIMPLE} />;
  }

  const best = values.indexOf(max);
  const H = 132;
  const summary = labels.map((l, i) => l + ' ' + money(values[i])).join('，');

  return (
    <div role="img" aria-label={'近 7 天每日收入：' + summary}>
      <div style={{ display: 'flex', alignItems: 'flex-end', gap: 8, height: H }}>
        {values.map((v, i) => {
          /* A zero day still gets a visible sliver, otherwise "sold nothing" and
             "no data for this day" look identical. */
          const h = Math.max(3, Math.round((v / max) * (H - 26)));
          return (
            <div
              key={i}
              style={{
                flex: 1,
                display: 'flex',
                flexDirection: 'column',
                justifyContent: 'flex-end',
                alignItems: 'center',
                gap: 4,
              }}
            >
              {i === best ? (
                <span
                  style={{
                    fontSize: 11,
                    color: '#5b6270',
                    fontVariantNumeric: 'tabular-nums',
                    whiteSpace: 'nowrap',
                  }}
                >
                  {money(v)}
                </span>
              ) : null}
              <div
                title={labels[i] + '　' + money(v)}
                className="dash-bar"
                style={{
                  width: '100%',
                  height: h,
                  /* Rounded data-end at the top only: the bar is anchored to the
                     baseline, and rounding the bottom would lift it off its own axis. */
                  borderRadius: '4px 4px 0 0',
                  background: v === 0 ? '#e3e6ea' : '#009688',
                }}
              />
            </div>
          );
        })}
      </div>
      <div
        style={{
          display: 'flex',
          gap: 8,
          marginTop: 6,
          borderTop: '1px solid #eceef1',
          paddingTop: 6,
        }}
      >
        {labels.map((l, i) => (
          <div
            key={i}
            style={{
              flex: 1,
              textAlign: 'center',
              fontSize: 11,
              color: '#8a919e',
              fontVariantNumeric: 'tabular-nums',
            }}
          >
            {l}
          </div>
        ))}
      </div>
    </div>
  );
}

export default function Dashboard() {
  const [loading, setLoading] = useState(true);
  const [data, setData] = useState({});

  useEffect(() => {
    getDashboard()
      .then((res) => setData(res.data?.data || res.data))
      .catch(() => {})
      .finally(() => setLoading(false));
  }, []);

  if (loading) {
    return <Skeleton active paragraph={{ rows: 8 }} />;
  }

  const pending = Number(data.pending_orders || 0);

  const columns = [
    {
      title: '订单号',
      dataIndex: 'order_no',
      /* Monospace with tabular figures: order numbers are all the same length, so in
         a column they line up and an odd one out is visible without reading it.
         ellipsis rather than wrap — a 19-digit number broken across two lines is
         harder to read than a truncated one, and the full value is one click away. */
      ellipsis: true,
      width: 170,
      render: (v, r) => (
        <Link
          to={'/admin/orders/' + r.id}
          style={{ fontFamily: 'ui-monospace, Consolas, monospace', whiteSpace: 'nowrap' }}
        >
          {v}
        </Link>
      ),
    },
    { title: '商品', dataIndex: 'product_name', ellipsis: true },
    {
      title: '金额',
      dataIndex: 'total_amount',
      width: 110,
      align: 'right',
      render: (v) => <span style={{ fontVariantNumeric: 'tabular-nums' }}>{money(v)}</span>,
    },
    {
      title: '状态',
      dataIndex: 'status',
      width: 90,
      render: (v) => <Tag color={STATUS[v]?.color}>{STATUS[v]?.text || v}</Tag>,
    },
    { title: '时间', dataIndex: 'created_at', width: 150 },
  ];

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
      <Row gutter={[16, 16]}>
        <Col xs={12} lg={6}>
          <Stat
            label="今日收入"
            value={money(data.today_revenue)}
            tone="money"
            hint={'本月 ' + money(data.month_revenue)}
          />
        </Col>
        <Col xs={12} lg={6}>
          <Stat label="今日订单" value={data.today_orders ?? 0} hint={'累计 ' + (data.total_orders ?? 0) + ' 笔'} />
        </Col>
        <Col xs={12} lg={6}>
          {/* The only tile that can ask for something, and the only one that links
              away. Amber solely when the count is non-zero — a tile that is always
              coloured stops meaning anything. */}
          <Stat
            label="待支付订单"
            value={pending}
            tone={pending > 0 ? 'attention' : undefined}
            hint={pending > 0 ? '点击查看，可手动确认或关闭' : '没有待处理的订单'}
            to={pending > 0 ? '/admin/orders' : undefined}
          />
        </Col>
        <Col xs={12} lg={6}>
          <Stat label="在售商品" value={data.total_products ?? 0} hint="仅统计已上架的" to="/admin/products" />
        </Col>
      </Row>

      <Card title="近 7 天收入" size="small">
        <RevenueBars labels={data.chart_labels} data={data.chart_data} />
      </Card>

      <Card title="最近订单" size="small" extra={<Link to="/admin/orders">全部订单</Link>}>
        <Table
          rowKey="id"
          size="small"
          columns={columns}
          dataSource={data.recent_orders || []}
          pagination={false}
          locale={{ emptyText: <Empty description="还没有订单" image={Empty.PRESENTED_IMAGE_SIMPLE} /> }}
        />
      </Card>
    </div>
  );
}
