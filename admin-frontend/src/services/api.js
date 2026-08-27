import axios from 'axios';

const api = axios.create({
  baseURL: '/api/admin',
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
});

// Attach CSRF token from meta tag
api.interceptors.request.use((config) => {
  const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
  if (token) {
    config.headers['X-CSRF-TOKEN'] = token;
  }
  return config;
});

// Handle 401 responses globally
api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      window.location.href = '/admin/login';
    }
    return Promise.reject(error);
  }
);

// Auth
export const login = (username, password) =>
  api.post('/login', { username, password });

export const logout = () =>
  api.post('/logout');

export const getMe = () =>
  api.get('/me');

// Dashboard
export const getDashboard = () =>
  api.get('/dashboard');

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

export default api;
