import axios from 'axios';
import { ADMIN_BASE, API_BASE } from '../base';
import { adminContextChanged, adminSessionEpoch, onAdminSessionChange, resetAdminSession } from './sessionLifecycle';

const pendingRequests = new Set();
let adminContext = '';
onAdminSessionChange(({ remote }) => {
  pendingRequests.forEach(controller => controller.abort());
  pendingRequests.clear();
  adminContext = '';
  if (remote) window.location.reload();
});
const finishRequest = (config) => {
  if (!config) return;
  pendingRequests.delete(config._adminAbortController);
  config._adminDetachSignal?.();
};
const sessionChanged = (config) => config?._adminSessionEpoch !== undefined &&
  config._adminSessionEpoch !== adminSessionEpoch();
const staleSessionError = () => new axios.CanceledError('管理员会话已变化，旧请求已取消。');

const api = axios.create({
  baseURL: API_BASE,
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
});

const readMetaToken = () =>
  document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

let csrfToken = readMetaToken();

// Laravel rotates the CSRF token on login and logout. The page was served with the
// pre-login token, so we track the current one here and keep the meta tag in sync.
function setCsrfToken(token) {
  if (!token) return;
  csrfToken = token;
  const meta = document.querySelector('meta[name="csrf-token"]');
  if (meta) meta.setAttribute('content', token);
}

api.interceptors.request.use((config) => {
  const controller = new AbortController();
  const upstream = config.signal;
  const cancel = () => controller.abort();
  if (upstream?.aborted) cancel();
  else upstream?.addEventListener('abort', cancel, { once: true });
  config._adminDetachSignal = () => upstream?.removeEventListener('abort', cancel);
  config._adminAbortController = controller;
  config._adminSessionEpoch = adminSessionEpoch();
  config.signal = controller.signal;
  pendingRequests.add(controller);
  if (adminContext) config.headers['X-Admin-Context'] = adminContext;
  else delete config.headers['X-Admin-Context'];
  if (csrfToken) {
    config.headers['X-CSRF-TOKEN'] = csrfToken;
  }

  // File uploads must NOT inherit the instance-level application/json header: the
  // browser has to set multipart/form-data itself so it can append the boundary.
  // Dropping the header here lets axios/XHR fill it in from the FormData body.
  if (typeof FormData !== 'undefined' && config.data instanceof FormData) {
    if (config.headers && typeof config.headers.delete === 'function') {
      config.headers.delete('Content-Type');
    } else if (config.headers) {
      delete config.headers['Content-Type'];
      delete config.headers['content-type'];
    }
  }

  // ProTable sends pageSize; some pages forward it as per_page. Send both so the
  // page-size selector works no matter which name the controller reads.
  if (config.params && typeof config.params === 'object') {
    const p = config.params;
    if (p.pageSize != null && p.per_page == null) p.per_page = p.pageSize;
    if (p.per_page != null && p.pageSize == null) p.pageSize = p.per_page;
  }

  return config;
});

/**
 * 从后台外壳页面重新取一枚 CSRF token。
 *
 * 外壳是 Blade 渲染的，每次响应都带一枚当前会话有效的 token（见
 * resources/views/admin/spa.blade.php）。所以拿它刷新，不需要新增接口。
 */
async function refreshCsrfToken(expectedEpoch) {
  const controller = new AbortController();
  pendingRequests.add(controller);
  try {
    const res = await fetch(ADMIN_BASE || '/', {
      credentials: 'same-origin', signal: controller.signal,
      headers: { Accept: 'text/html' },
    });
    const html = await res.text();
    if (expectedEpoch !== adminSessionEpoch()) throw staleSessionError();
    const token = html.match(/name="csrf-token"\s+content="([^"]+)"/)?.[1];
    if (!token) throw new Error('no csrf token in shell');
    setCsrfToken(token);
    return token;
  } finally { pendingRequests.delete(controller); }
}

api.interceptors.response.use(
  (response) => {
    finishRequest(response.config);
    if (sessionChanged(response.config)) throw staleSessionError();
    if (response.headers['x-admin-context']) adminContext = response.headers['x-admin-context'];
    return response;
  },
  async (error) => {
    const status = error.response?.status;
    const config = error.config;
    finishRequest(config);
    if (sessionChanged(config)) return Promise.reject(staleSessionError());

    if (status === 401) {
      resetAdminSession({ broadcast: false });
      if (!window.location.pathname.startsWith(`${ADMIN_BASE}/login`)) {
        window.location.href = `${ADMIN_BASE}/login`;
      }
    }
    if (adminContextChanged(error.response, config?.headers?.['X-Admin-Context'])) {
      resetAdminSession({ broadcast: false });
      window.location.reload();
      return Promise.reject(staleSessionError());
    }

    // 419 = CSRF token 过期或不匹配。
    //
    // 这里原来是 window.location.reload()。整页刷新确实能换到一枚新 token，但代价是
    // 把页面上所有未提交的编辑一起冲掉——系统设置改成「改完自动保存」之后，这个代价
    // 变得不可接受：一次 419 就会静默吃掉操作员刚敲进去的内容，而他什么都没点。
    //
    // 改成：静默取一枚新 token，把原请求重发一次。只重试一次（_csrfRetried 标记），
    // 避免 token 一直不对时打成死循环。重试仍失败就把错误正常抛给调用方，由调用方
    // 决定怎么提示——设置页会显示「保存失败」并保留输入，用户可以点重试。
    if (status === 419 && config && !config._csrfRetried) {
      config._csrfRetried = true;
      try {
        const token = await refreshCsrfToken(config._adminSessionEpoch);
        config.headers = { ...config.headers, 'X-CSRF-TOKEN': token };
        return api.request(config);
      } catch (e) {
        // 取不到新 token（多半是会话真的没了），交给下面正常报错。
      }
    }

    return Promise.reject(error);
  }
);

// Auth
export const login = async (username, password) => {
  resetAdminSession({ broadcast: false });
  const res = await api.post('/login', { username, password });
  resetAdminSession();
  setCsrfToken(res.data?.csrf_token);
  return res;
};

export const logout = async () => {
  resetAdminSession({ broadcast: false });
  let succeeded = false;
  try {
    const res = await api.post('/logout');
    setCsrfToken(res.data?.csrf_token);
    succeeded = true;
    return res;
  } finally { resetAdminSession({ broadcast: succeeded }); }
};

export const getMe = () =>
  api.get('/me');

// The server rotates the session (and therefore the CSRF token) on a password change,
// so the new token has to be adopted or every later write returns 419.
export const changePassword = async (data) => {
  const res = await api.post('/password', data);
  resetAdminSession();
  setCsrfToken(res.data?.csrf_token);
  return res;
};

// Dashboard
export const getDashboard = () =>
  api.get('/dashboard');

// Uploads
// Responds 200 with { url, path }; url is the public "/storage/..." string stored
// on the model. Errors come back 422 with { message }.
export const uploadImage = (file) => {
  const form = new FormData();
  form.append('file', file);
  return api.post('/upload', form);
};

// Categories
export const getCategories = (params) =>
  api.get('/categories', { params });

export const createCategory = (data) =>
  api.post('/categories', data);

export const updateCategory = (id, data) =>
  api.put(`/categories/${id}`, data);

export const deleteCategory = (id) =>
  api.delete(`/categories/${id}`);

// Products
export const getProducts = (params) =>
  api.get('/products', { params });

// Coupon selectors must include products beyond the first 200 rows, including
// inactive products already referenced by an existing coupon.
export const getAllProducts = async () => {
  const products = [];
  for (let page = 1; ; page += 1) {
    const res = await getProducts({ page, per_page: 200 });
    const body = res.data ?? {};
    const rows = Array.isArray(body) ? body : (body.data ?? []);
    products.push(...rows);
    if (rows.length === 0 || products.length >= (body.total ?? rows.length)) break;
  }
  return products;
};

export const getProduct = (id) =>
  api.get(`/products/${id}`);

export const createProduct = (data) =>
  api.post('/products', data);

export const updateProduct = (id, data) =>
  api.put(`/products/${id}`, data);

export const deleteProduct = (id) =>
  api.delete(`/products/${id}`);

// Product Cards
export const getProductCards = (productId, params) =>
  api.get(`/products/${productId}/cards`, { params });

export const importCards = (productId, data) =>
  api.post(`/products/${productId}/cards/import`, data);

export const deleteCard = (id) =>
  api.delete(`/cards/${id}`);

export const batchDeleteCards = (ids) =>
  api.delete('/cards/batch-destroy', { data: { ids } });

// Manual sold/unsold flip. Only 'unsold' and 'sold' are accepted; 'locked' is owned by
// the pending-order flow. The server refuses with 422 { message } when the card is
// locked or still attached to a real order, so callers must surface that message.
export const setCardStatus = (id, status) =>
  api.patch(`/cards/${id}/status`, { status });

// Orders
export const getOrders = (params) =>
  api.get('/orders', { params });

export const getOrder = (id) =>
  api.get(`/orders/${id}`);

export const closeOrder = (id) =>
  api.post(`/orders/${id}/close`);

export const markPaid = (id) =>
  api.post(`/orders/${id}/paid`);

export const resendOrder = (id) =>
  api.post(`/orders/${id}/resend`);

export const exportOrders = (params) =>
  api.get('/orders/export', { params, responseType: 'blob' });

// Articles
export const getArticles = (params) =>
  api.get('/articles', { params });

export const createArticle = (data) =>
  api.post('/articles', data);

export const updateArticle = (id, data) =>
  api.put(`/articles/${id}`, data);

export const deleteArticle = (id) =>
  api.delete(`/articles/${id}`);

// Article Categories
export const getArticleCategories = (params) =>
  api.get('/article-categories', { params });

export const createArticleCategory = (data) =>
  api.post('/article-categories', data);

export const updateArticleCategory = (id, data) =>
  api.put(`/article-categories/${id}`, data);

export const deleteArticleCategory = (id) =>
  api.delete(`/article-categories/${id}`);

// Coupons
export const getCoupons = (params) =>
  api.get('/coupons', { params });

export const createCoupon = (data) =>
  api.post('/coupons', data);

export const updateCoupon = (id, data) =>
  api.put(`/coupons/${id}`, data);

export const deleteCoupon = (id) =>
  api.delete(`/coupons/${id}`);

// Blacklists
export const getBlacklists = (params) =>
  api.get('/blacklists', { params });

export const createBlacklist = (data) =>
  api.post('/blacklists', data);

export const updateBlacklist = (id, data) =>
  api.put(`/blacklists/${id}`, data);

export const deleteBlacklist = (id) =>
  api.delete(`/blacklists/${id}`);

// Logs
export const getLogs = (params) =>
  api.get('/logs', { params });

// Settings
export const getSettings = () =>
  api.get('/settings');

export const updateSettings = (data, options) =>
  api.post('/settings', data, options);

export const sendTestEmail = (email) =>
  api.post('/settings/test-email', { email });

// API access tokens. Only createApiToken returns a one-time plaintext secret.
export const getApiTokens = (params) => api.get('/api-tokens', { params });
export const createApiToken = (data) => api.post('/api-tokens', data);
export const updateApiToken = (id, data) => api.put(`/api-tokens/${id}`, data);
export const deleteApiToken = (id) => api.delete(`/api-tokens/${id}`);

export default api;
export const getNotifications = (params) => api.get('/notifications', { params });
export const retryNotification = (id) => api.post(`/notifications/${id}/retry`);
