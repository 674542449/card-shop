import dayjs from 'dayjs';

export const ORDER_STATUS_MAP = {
  pending: { text: '待支付', color: 'gold' },
  paid: { text: '已支付', color: 'green' },
  closed: { text: '已关闭', color: 'default' },
  expired: { text: '已过期', color: 'red' },
};

export const ORDER_STATUS_ENUM = Object.fromEntries(
  Object.entries(ORDER_STATUS_MAP).map(([key, value]) => [key, { text: value.text }])
);

export const PAYMENT_METHOD_MAP = {
  alipay: '支付宝',
  wechat: '微信支付',
  usdt_trc20: 'USDT (TRC20)',
  usdt_bep20: 'USDT (BEP20)',
  usdt_polygon: 'USDT (Polygon)',
  manual: '人工确认',
};

export const PAYMENT_METHOD_ENUM = Object.fromEntries(
  Object.entries(PAYMENT_METHOD_MAP).map(([key, text]) => [key, { text }])
);

export const REFUND_STATUS_MAP = {
  requested: { text: '待审核', color: 'gold' },
  approved: { text: '已批准，待退款', color: 'blue' },
  completed: { text: '已退款', color: 'green' },
  rejected: { text: '已拒绝', color: 'red' },
};

export const NOTIFICATION_STATUSES = {
  pending: '等待发送 / 重试',
  processing: '发送中',
  sent: '已交给发送服务',
  failed: '发送失败',
  skipped: '已跳过',
};

export const NOTIFICATION_TYPES = {
  order_email: '卡密交付邮件',
  refund_email: '退款结果邮件',
  new_order: '新订单通知',
  payment_review: '付款待核对通知',
  low_stock: '低库存预警',
};

export const formatDateTime = (value) => (value ? dayjs(value).format('YYYY-MM-DD HH:mm:ss') : '-');
export const formatDateMinute = (value) => (value ? dayjs(value).format('YYYY-MM-DD HH:mm') : '-');
export const formatMoney = (value) =>
  '¥' + Number(value || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/** Unwraps either a plain array response or a Laravel paginated `{ data, total }` response for ProTable. */
export function toTablePage(res) {
  const body = res?.data ?? {};
  const list = Array.isArray(body) ? body : (body.data ?? []);
  return {
    data: list,
    total: Array.isArray(body) ? list.length : (body.total ?? list.length),
    success: true,
  };
}

/** Triggers a browser download from a Blob or raw payload and revokes the object URL safely. */
export function downloadBlob(payload, filename, mimeType) {
  const blob = payload instanceof Blob ? payload : new Blob([payload], mimeType ? { type: mimeType } : undefined);
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}
