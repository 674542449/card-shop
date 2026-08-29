import axios from 'axios';

const api = axios.create({
  baseURL: '/api/admin',
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

api.interceptors.response.use(
  (response) => response,
  (error) => {
    const status = error.response?.status;

    if (status === 401) {
      if (!window.location.pathname.startsWith('/admin/login')) {
        window.location.href = '/admin/login';
      }
    }

    // 419 = expired/mismatched CSRF token. A full reload re-renders the shell with a
    // valid token rather than leaving the admin stuck on every write.
    if (status === 419) {
      window.location.reload();
    }

    return Promise.reject(error);
  }
);

// Auth
export const login = async (username, password) => {
  const res = await api.post('/login', { username, password });
  setCsrfToken(res.data?.csrf_token);
  return res;
};

export const logout = async () => {
  const res = await api.post('/logout');
  setCsrfToken(res.data?.csrf_token);
  return res;
};

export const getMe = () =>
  api.get('/me');

// The server rotates the session (and therefore the CSRF token) on a password change,
// so the new token has to be adopted or every later write returns 419.
export const changePassword = async (data) => {
  const res = await api.post('/password', data);
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

export const deleteBlacklist = (id) =>
  api.delete(`/blacklists/${id}`);

// Logs
export const getLogs = (params) =>
  api.get('/logs', { params });

// Settings
export const getSettings = () =>
  api.get('/settings');

export const updateSettings = (data) =>
  api.post('/settings', data);

export const sendTestEmail = (email) =>
  api.post('/settings/test-email', { email });

export default api;
