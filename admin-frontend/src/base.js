/**
 * 后台挂载的路径，运行时从页面上的 meta 标签读。
 *
 * 服务端由 .env 的 ADMIN_PATH 决定（见 app/Helpers/helpers.php 的 admin_path()），
 * 通过 resources/views/admin/spa.blade.php 写进 <meta name="admin-base">。
 *
 * 做成运行时读取而不是构建期常量，是为了让同一份构建产物适用于任何路径 —— 否则每换
 * 一次 ADMIN_PATH 都要重新 npm run build 并提交 public/admin-assets/，而那份产物是
 * 提交进仓库的，等于每个部署都得自己构建一遍。
 *
 * 兜底 '/admin' 是为了开发时直接跑 vite dev server（那时没有 Blade 渲染的 meta）。
 */
const meta = typeof document !== 'undefined'
  ? document.querySelector('meta[name="admin-base"]')
  : null;

const raw = meta?.getAttribute('content') || '/admin';

// 去掉结尾斜杠：React Router 的 basename 和拼 URL 都不接受它。
export const ADMIN_BASE = raw.replace(/\/+$/, '') || '/admin';

/** 后台 API 的前缀，永远跟着 ADMIN_BASE 走。 */
export const API_BASE = `/api${ADMIN_BASE}`;
