import { test, after } from 'node:test';
import assert from 'node:assert/strict';
import { build } from 'esbuild';
import { mkdir, unlink } from 'node:fs/promises';
import { pathToFileURL, fileURLToPath } from 'node:url';
import { JSDOM } from 'jsdom';

// Mount the real pages and Ant Design components. Only network I/O and the rich
// editor/upload integrations are replaced; routing, effects and dialogs are real.
const dom = new JSDOM('<html><body><div id="root"></div></body></html>', { url: 'http://localhost/admin' });
for (const key of ['window', 'document', 'HTMLElement', 'Element', 'SVGElement', 'Node', 'ShadowRoot', 'MutationObserver']) globalThis[key] = dom.window[key];
globalThis.getComputedStyle = dom.window.getComputedStyle.bind(dom.window);
globalThis.IS_REACT_ACT_ENVIRONMENT = true;
globalThis.requestAnimationFrame = callback => setTimeout(callback, 0);
globalThis.cancelAnimationFrame = clearTimeout;
globalThis.ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };
window.matchMedia = query => ({ matches: false, media: query, addListener() {}, removeListener() {}, addEventListener() {}, removeEventListener() {} });
window.scrollTo = () => {};
// jsdom does not compute pseudo-element styles; Ant Design only needs the base.
const computed = window.getComputedStyle.bind(window);
window.getComputedStyle = element => computed(element);
globalThis.getComputedStyle = window.getComputedStyle;
const React = await import('react');
const { act } = React;
const { createRoot } = await import('react-dom/client');
const { createMemoryRouter, RouterProvider, Outlet } = await import('react-router-dom');
const directory = fileURLToPath(new URL('../node_modules/.cache/', import.meta.url));
await mkdir(directory, { recursive: true });
const output = directory + `/admin-review-${process.pid}.mjs`;
await build({ stdin: { contents: "export {default as OrderDetail} from './src/pages/OrderDetail.jsx'; export {default as Settings} from './src/pages/Settings.jsx'; export {default as TaskCenter} from './src/pages/TaskCenter.jsx'; export {default as Operations} from './src/pages/Operations.jsx'; export {default as Dashboard} from './src/pages/Dashboard.jsx'; export {default as ProductCards} from './src/pages/ProductCards.jsx'; export {default as Products} from './src/pages/Products.jsx'; export {default as Articles} from './src/pages/Articles.jsx';", resolveDir: fileURLToPath(new URL('../', import.meta.url)), loader: 'jsx' },
  outfile: output, bundle: true, format: 'esm', platform: 'node', packages: 'external', plugins: [{ name: 'fixture-boundaries', setup(b) {
    b.onResolve({ filter: /services\/api$/ }, () => ({ path: 'api', namespace: 'fixture' }));
    b.onResolve({ filter: /components\/(RichTextEditor|ImageUploader)$/ }, () => ({ path: 'editor', namespace: 'fixture' }));
    b.onLoad({ filter: /.*/, namespace: 'fixture' }, args => ({ resolveDir: fileURLToPath(new URL('../', import.meta.url)), contents: args.path === 'editor'
      ? `import React from 'react'; export default function Editor({ value = '', onChange, id, placeholder }) { return React.createElement('textarea', { id, placeholder, 'data-testid': 'rich-editor', value: value ?? '', onChange: e => onChange?.(e.target.value) }); }`
      :
      `const call = name => (...args) => globalThis.reviewApi[name](...args);
       export const getOrder=call('getOrder'), closeOrder=call('closeOrder'), markPaid=call('markPaid'), resendOrder=call('resendOrder'), getSettings=call('getSettings'), updateSettings=call('updateSettings'), sendTestEmail=call('sendTestEmail'), sendTestTelegram=call('sendTestTelegram'), testBackupStorage=call('testBackupStorage');
       export const getNotifications=call('getNotifications'), retryNotification=call('retryNotification'), getDashboard=call('getDashboard'), getProductCards=call('getProductCards'), importCards=call('importCards'), deleteCard=call('deleteCard'), batchDeleteCards=call('batchDeleteCards'), setCardStatus=call('setCardStatus');
       export const getProducts=call('getProducts'), getProduct=call('getProduct'), createProduct=call('createProduct'), updateProduct=call('updateProduct'), batchUpdateProductDescription=call('batchUpdateProductDescription'), batchUpdateProductPrice=call('batchUpdateProductPrice'), deleteProduct=call('deleteProduct');
       export const getCategories=call('getCategories'), createCategory=call('createCategory'), updateCategory=call('updateCategory'), deleteCategory=call('deleteCategory');
       export const getArticles=call('getArticles'), getArticle=call('getArticle'), createArticle=call('createArticle'), updateArticle=call('updateArticle'), deleteArticle=call('deleteArticle');
       export const getArticleCategories=call('getArticleCategories'), createArticleCategory=call('createArticleCategory'), updateArticleCategory=call('updateArticleCategory'), deleteArticleCategory=call('deleteArticleCategory');
       export default { get: call('get'), post: call('post'), put: call('put') };`, loader: 'js' }));
  }}] });
const { OrderDetail, Settings, TaskCenter, Operations, Dashboard, ProductCards, Products, Articles } = await import(pathToFileURL(output));
after(async () => { await unlink(output); dom.window.close(); });
const deferred = () => { let resolve, reject; const promise = new Promise((a, b) => { resolve = a; reject = b; }); return { promise, resolve, reject }; };
const fixtureOrder = id => ({ id, order_no: `DUMMY-ORDER-${id}`, status: 'paid', payment_method: 'alipay', quantity: 1, unit_price: '10.00', total_amount: '10.00', cards: [], refund_balance: { reserved: '0.00', available: '10.00' } });
const text = () => document.body.textContent.replace(/\s+/g, '');
const click = async label => {
  const button = [...document.querySelectorAll('button')].find(element => element.textContent.replace(/\s+/g, '') === label);
  assert.ok(button, `Missing button: ${label}`);
  await act(async () => { button.click(); });
};
const owner = { id: 1, is_active: true, permission_definition: { capabilities: [...['overview','catalog','cards','payments','orders','refunds','content','coupons','blacklists','logs','settings','tokens','notifications','maintenance'].flatMap(area=>[`${area}:read`,`${area}:write`]), 'self','settings.test_email','settings.test_telegram','settings.test_backup_storage','backups.read','backups.write','health.acknowledge', 'orders.replace_cards', 'orders.refund', 'reconciliation.read', 'reconciliation.retry', 'orders:read', 'refunds:read', 'notifications:read', 'catalog:read'], pages: [
  {path:'/orders',capability:'orders:read',children:true}, {path:'/refunds',capability:'refunds:read'}, {path:'/tasks',any:['notifications:read','content:read','reconciliation.read']}, {path:'/products',capability:'catalog:read',children:true},
] } };
async function mount(path, admin = owner) {
  const router = createMemoryRouter([{ element: React.createElement(Outlet, { context: admin }), children: [{ path: '/orders/:id', element: React.createElement(OrderDetail) }, { path: '/settings', element: React.createElement(Settings) }, { path: '/tasks', element: React.createElement(TaskCenter) }, { path: '/operations', element: React.createElement(Operations) }, { path: '/', element: React.createElement(Dashboard) }, { path: '/products/:productId/cards', element: React.createElement(ProductCards) }, { path: '/products', element: React.createElement(Products) }, { path: '/articles', element: React.createElement(Articles) }] }], { initialEntries: [path] });
  const root = createRoot(document.getElementById('root'));
  await act(async () => { root.render(React.createElement(RouterProvider, { router })); });
  return { router, async close() { await act(async () => root.unmount()); router.dispose(); } };
}

test('switching order routes rejects late data and resets the previous order dialog', async () => {
  const requests = [];
  globalThis.reviewApi = { getOrder(id, config) { const request = { id, config, ...deferred() }; requests.push(request); return request.promise; } };
  const view = await mount('/orders/1');
  try {
    await act(async () => { await view.router.navigate('/orders/2'); });
    assert.equal(requests[0].config.signal.aborted, true);
    await act(async () => { requests[1].resolve({ data: fixtureOrder(2) }); });
    await act(async () => { requests[0].resolve({ data: fixtureOrder(1) }); });
    assert.ok(text().includes('DUMMY-ORDER-2'));
    assert.ok(!text().includes('DUMMY-ORDER-1'));
    await click('售后换卡');
    assert.ok(document.querySelector('[role="dialog"]'));
    await act(async () => { await view.router.navigate('/orders/3'); });
    await act(async () => { requests[2].resolve({ data: fixtureOrder(3) }); });
    assert.ok(text().includes('DUMMY-ORDER-3'));
    assert.equal(document.querySelector('[role="dialog"]'), null);
  } finally { await view.close(); }
});

test('failed settings load offers retry without an editable empty form or writes', async () => {
  const writes = []; let loads = 0;
  globalThis.reviewApi = { getSettings: async () => { if (++loads === 1) throw new Error('offline'); return { data: { site_name: 'Preserved site name', _available_themes: ['default'] } }; }, updateSettings: async data => { writes.push(data); } };
  const view = await mount('/settings');
  try {
    assert.ok(text().includes('加载设置失败'));
    assert.equal(document.querySelector('form'), null);
    assert.deepEqual(writes, []);
    await click('重新加载设置');
    assert.ok(document.querySelector('form'));
    assert.ok([...document.querySelectorAll('input')].some(input => input.value === 'Preserved site name'));
    assert.deepEqual(writes, []);
    assert.equal(loads, 2);
  } finally { await view.close(); }
});

const settleTables = async () => { await act(async () => { await new Promise(resolve => setTimeout(resolve, 150)); }); };
const selectTab = async label => {
  const tab = [...document.querySelectorAll('[role="tab"]')].find(element => element.textContent === label);
  assert.ok(tab, `Missing tab: ${label}`);
  await act(async () => tab.click());
  await settleTables();
};
const activePanel = () => document.querySelector('[role="tabpanel"][aria-hidden="false"]') || document.querySelector('.ant-tabs-tabpane-active');

test('payment and mail groups expose all existing fields and save only on explicit submit', async () => {
  const writes = [];
  globalThis.reviewApi = { getSettings: async () => ({ data: { epay_api_url:'https://old.example.test', epusdt_api_url:'https://usdt.example.test', mail_host:'smtp.example.test', email_template_subject:'Delivery', _available_themes:['default'] } }), updateSettings: async data => { writes.push(data); return { data:{} }; } };
  const view = await mount('/settings');
  try {
    await selectTab('支付设置');
    assert.ok(activePanel().querySelector('[id$="_epay_api_url"]'));
    assert.ok(activePanel().querySelector('[id$="_epusdt_api_url"]'));
    assert.ok(activePanel().textContent.includes('同时作用于 EPay 和 USDT'));
    const input = activePanel().querySelector('[id$="_epay_api_url"]');
    await act(async () => {
      Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set.call(input, 'https://new.example.test');
      input.dispatchEvent(new window.Event('input', { bubbles:true }));
    });
    await selectTab('邮件设置');
    assert.ok(activePanel().querySelector('[id$="_mail_host"]'));
    assert.ok(activePanel().querySelector('[id$="_email_template_subject"]'));
    assert.ok(activePanel().querySelector('[id$="_email_template_body"]'));
    // Switching tabs or waiting must never auto-save settings.
    await settleTables();
    assert.deepEqual(writes, []);
    assert.ok(text().includes('有1项未保存更改'));
    await click('保存设置');
    await settleTables();
    assert.deepEqual(writes, [{epay_api_url:'https://new.example.test'}]);
    assert.ok(!text().includes('有1项未保存更改'));
    assert.ok(text().includes('更改已保存'));
  } finally { await view.close(); }
});

test('leaving or resetting settings never auto-saves unsaved edits', async () => {
  const writes = [];
  globalThis.reviewApi = {
    getSettings: async () => ({ data: { site_name: 'Original Shop', _available_themes: ['default'] } }),
    updateSettings: async data => { writes.push(data); return { data: {} }; },
  };
  const view = await mount('/settings');
  try {
    const input = activePanel().querySelector('[id$="_site_name"]');
    assert.equal(input.value, 'Original Shop');
    await act(async () => {
      Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set.call(input, 'Draft Name');
      input.dispatchEvent(new window.Event('input', { bubbles: true }));
    });
    assert.ok(text().includes('有1项未保存更改'));
    await click('重置未保存更改');
    assert.equal(input.value, 'Original Shop');
    assert.ok(!text().includes('有1项未保存更改'));
    assert.deepEqual(writes, []);
    await act(async () => {
      Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set.call(input, 'Abandoned Draft');
      input.dispatchEvent(new window.Event('input', { bubbles: true }));
    });
    await settleTables();
    assert.deepEqual(writes, []);
  } finally { await view.close(); }
  // Unmounting with unsaved edits must not auto-save either.
  assert.deepEqual(writes, []);
});

test('task center switches queues without losing notification filters or requesting other modules', async () => {
  const calls = [];
  globalThis.reviewApi = {
    getNotifications: async params => { calls.push(['notifications', params]); return {data:{data:[],total:0}}; },
    get: async (path, config) => { calls.push([path, config]); return {data:{data:[],total:0}}; },
  };
  const view = await mount('/tasks?tab=notifications&status=failed');
  try {
    await settleTables();
    assert.equal(calls[0][1].status, 'failed');
    assert.deepEqual(calls.map(c=>c[0]), ['notifications']);
    await selectTab('付款对账任务');
    assert.equal(view.router.state.location.search, '?tab=reconciliation');
    assert.ok(calls.some(c=>c[0]==='/maintenance/reconciliation-jobs'));
    await selectTab('搜索引擎推送');
    assert.ok(calls.some(c=>c[0]==='/seo-deliveries'));
    assert.ok(!calls.some(c=>c[0]==='/maintenance/health'));
    assert.ok(!text().includes('完整备份文件'));
  } finally { await view.close(); }
});

test('a stale incomplete access definition cannot mount other queues or offer writes', async () => {
  const calls = [];
  globalThis.reviewApi = {getNotifications: async () => {calls.push('notifications'); return {data:{data:[{id:1,status:'failed',type:'order_email'}],total:1}};}, get: async path => {calls.push(path); throw new Error('Unauthorized module requested');}};
  const view = await mount('/tasks?tab=seo', {id:1,is_active:true,permission_definition:{capabilities:['notifications:read']}});
  try {
    await settleTables();
    assert.deepEqual([...document.querySelectorAll('[role="tab"]')].map(e=>e.textContent), ['通知投递']);
    assert.deepEqual(calls, ['notifications']);
    assert.ok(![...document.querySelectorAll('button')].some(e=>e.textContent.includes('重新入队')));
  } finally { await view.close(); }
});

test('operations contains only health assets and backups', async () => {
  const calls=[];
  globalThis.reviewApi={get:async path=>{calls.push(path);return {data:{healthy:true,backups:{healthy:true},data:[],health:{pending:0}}};}};
  const view=await mount('/operations');
  try {
    assert.deepEqual([...document.querySelectorAll('[role="tab"]')].map(e=>e.textContent), ['运行健康','上传素材','备份与恢复']);
    assert.deepEqual(calls,['/maintenance/health']);
  } finally {await view.close();}
});

test('dashboard prioritizes actionable work and links approved refunds separately', async () => {
  globalThis.reviewApi={getDashboard:async()=>({data:{payment_review_orders:2,requested_refunds:3,approved_refunds:4,failed_notifications:5,low_stock_count:6,pending_orders:99}})};
  const view=await mount('/');
  try {
    const todo=document.querySelector('.admin-todo-card');
    assert.ok(todo);
    assert.ok(todo.textContent.includes('售后待审核（退款）'));
    assert.ok(!todo.textContent.includes('待付款'));
    for(const target of ['/orders?payment_review=1','/refunds?status=requested','/refunds?status=approved','/tasks?tab=notifications&status=failed','/products?low_stock=1']) {
      assert.ok([...todo.querySelectorAll('a')].some(e=>e.getAttribute('href')===target),target);
    }
    const stack=todo.querySelector('.admin-stat-stack');
    assert.ok(stack,'refund tile and sub-link should share a flex column wrapper to avoid overflow');
    assert.ok(stack.querySelector('a.dash-tile-link[href="/refunds?status=requested"]'));
    assert.ok(stack.querySelector('a.admin-sub-link[href="/refunds?status=approved"]'));
    assert.equal(stack.querySelector('a a'),null,'links must never be nested');
  } finally {await view.close();}
});

test('inventory pause and offline sale are distinct actions and order stock stays protected', async () => {
  const cards=[{id:1,content:'DUMMY-AVAILABLE',status:'unsold',order_id:null},{id:2,content:'DUMMY-PAUSED',status:'disabled',order_id:null},
    {id:3,content:'DUMMY-OFFLINE',status:'sold',order_id:null},{id:4,content:'DUMMY-DELIVERED',status:'sold',order_id:9},{id:5,content:'DUMMY-LOCKED',status:'locked',order_id:10}];
  const writes=[];
  globalThis.reviewApi={getProductCards:async()=>({data:{data:cards,total:5,stats:{total:5,unsold:1,sold:2,locked:1,disabled:1}}}),setCardStatus:async(id,status)=>{writes.push([id,status]);return {data:{message:'Updated'}};}};
  const view=await mount('/products/1/cards');
  try {
    await settleTables();
    const row=id=>document.querySelector(`tr[data-row-key="${id}"]`);
    assert.ok(row(1).textContent.includes('暂停销售'));
    assert.ok(row(1).textContent.includes('登记线下售出'));
    assert.ok(row(2).textContent.includes('恢复销售'));
    assert.ok(!row(2).textContent.includes('登记线下售出'));
    assert.ok(row(3).textContent.includes('撤销线下售出'));
    assert.ok(!row(4).textContent.includes('撤销线下售出'));
    assert.ok(!/暂停销售|登记线下售出|恢复销售|撤销线下售出|删除/.test(row(5).textContent));
    await click('暂停销售');
    await click('确认');
    assert.deepEqual(writes,[[1,'disabled']]);
  } finally {await view.close();}
});

test('switching inventory products discards selection and the previous import dialog', {timeout:10000}, async () => {
  const reads=[];
  globalThis.reviewApi={getProductCards:async id=>{reads.push(id);return {data:{data:[{id:Number(id)*100,content:'DUMMY-PRODUCT-'+id,status:'unsold',order_id:null}],total:1,product:{name:'Product '+id}}};}};
  const view=await mount('/products/1/cards');
  try {
    await settleTables();
    const checkbox=document.querySelector('tr[data-row-key="100"] input[type="checkbox"]');
    assert.ok(checkbox);
    await act(async()=>checkbox.click());
    assert.ok(text().includes('批量删除'));
    await click('导入卡密');
    assert.ok(document.querySelector('[role="dialog"]'));
    await act(async()=>{await view.router.navigate('/products/2/cards');});
    await settleTables();
    assert.ok(reads.includes('2'));
    assert.equal(document.querySelector('[role="dialog"]'),null);
    assert.ok(!text().includes('批量删除'));
    assert.ok(text().includes('DUMMY-PRODUCT-2'));
    assert.ok(!text().includes('DUMMY-PRODUCT-1'));
  } finally {await view.close();}
});

test('late inventory responses cannot populate another product and old requests are aborted', {timeout:10000}, async () => {
  const requests=[];
  globalThis.reviewApi={getProductCards(id,params,config){const request={id,config,...deferred()};requests.push(request);return request.promise;}};
  const view=await mount('/products/1/cards');
  try {
    await settleTables();
    await act(async()=>{await view.router.navigate('/products/2/cards');});
    await settleTables();
    assert.equal(requests[0].config?.signal.aborted,true);
    const payload=id=>({data:{data:[{id,content:'DUMMY-PRODUCT-'+id,status:'unsold'}],total:1,product:{name:'Product '+id}}});
    await act(async()=>{requests.find(r=>r.id==='2').resolve(payload(2));});
    await act(async()=>{requests[0].resolve(payload(1));});
    assert.ok(text().includes('DUMMY-PRODUCT-2'));
    assert.ok(!text().includes('DUMMY-PRODUCT-1'));
  } finally {
    for(const request of requests) request.resolve({data:{data:[],total:0}});
    await view.close();
  }
});

test('product editor keeps the latest selection when earlier detail requests finish late', {timeout:15000}, async () => {
  const requests=[];
  globalThis.reviewApi={getCategories:async()=>({data:{data:[{id:1,name:'Fixture'}]}}),getProducts:async()=>({data:{data:[{id:1,name:'First product'},{id:2,name:'Second product'}],total:2}}),
    getProduct(id,config){const request={id,config,...deferred()};requests.push(request);return request.promise;}};
  const view=await mount('/products');
  try {
    await settleTables();
    const edit=async id=>{const button=[...document.querySelectorAll(`tr[data-row-key="${id}"] button`)].find(e=>e.textContent==='编辑');assert.ok(button);await act(async()=>button.click());};
    await edit(1);await edit(2);
    assert.equal(requests[0].config.signal.aborted,true);
    await act(async()=>requests[1].resolve({data:{id:2,name:'Second product',price:'10.00',category_id:1,is_active:true}}));
    await act(async()=>requests[0].resolve({data:{id:1,name:'First product',price:'1.00',category_id:1,is_active:true}}));
    const drawerName=()=>document.querySelector('.ant-drawer [id$="_name"]');
    assert.ok(drawerName());
    assert.equal(drawerName().value,'Second product');
    await click('新增商品');
    assert.equal(drawerName().value,'');
  } finally {
    for(const request of requests)request.resolve({data:{}});
    await view.close();
  }
});

test('consolidated catalog and content workspaces manage categories in tabs without duplicate shortcuts', {timeout:15000}, async () => {
  globalThis.reviewApi = {
    getCategories: async () => ({ data: { data: [{ id: 1, name: '软件订阅', slug: 'software', is_active: true, products_count: 3 }], total: 1 } }),
    getProducts: async () => ({ data: { data: [{ id: 1, name: 'Pro 授权码', price: '19.00', stock_count: 8, is_active: true }], total: 1 } }),
    getArticleCategories: async () => ({ data: { data: [{ id: 1, name: '使用指南', slug: 'guides', articles_count: 2 }], total: 1 } }),
    getArticles: async () => ({ data: { data: [{ id: 1, title: '接入说明', is_published: true, views: 10 }], total: 1 } }),
    getDashboard: async () => ({ data: { today_revenue: 100, pending_orders: 1 } }),
    getSettings: async () => ({ data: { site_name: 'CardShop', site_theme: 'default', _available_themes: ['default'] } }),
    updateSettings: async () => ({ data: {} }),
  };
  const productsView = await mount('/products');
  try {
    await settleTables();
    assert.deepEqual([...document.querySelectorAll('[role="tab"]')].map(e => e.textContent), ['商品列表', '商品分类']);
    await selectTab('商品分类');
    assert.equal(productsView.router.state.location.search, '?tab=categories');
    assert.ok(text().includes('软件订阅'));
    assert.ok(text().includes('新增分类'));
  } finally {
    await productsView.close();
  }

  const articlesView = await mount('/articles?tab=categories');
  try {
    await settleTables();
    assert.deepEqual([...document.querySelectorAll('[role="tab"]')].map(e => e.textContent), ['文章列表', '文章分类']);
    assert.ok(text().includes('使用指南'));
  } finally {
    await articlesView.close();
  }

  const dashView = await mount('/');
  try {
    assert.ok(!text().includes('常用操作'));
    assert.ok(!text().includes('打理商品'));
  } finally {
    await dashView.close();
  }

  const settingsView = await mount('/settings');
  try {
    assert.ok(!text().includes('打开账户设置'));
    assert.ok(text().includes('默认主题（现代数字工作台）'));
  } finally {
    await settingsView.close();
  }
});

test('article editor fetches fresh detail and aborts superseded requests', {timeout:15000}, async () => {
  const requests = [];
  globalThis.reviewApi = {
    getArticleCategories: async () => ({ data: { data: [{ id: 1, name: '使用指南' }] } }),
    getArticles: async () => ({ data: { data: [{ id: 1, title: '旧标题 A' }, { id: 2, title: '旧标题 B' }], total: 2 } }),
    getArticle(id, config) {
      const request = { id, config, ...deferred() };
      requests.push(request);
      return request.promise;
    },
  };
  const view = await mount('/articles');
  try {
    await settleTables();
    const edit = async (id) => {
      const button = [...document.querySelectorAll(`tr[data-row-key="${id}"] button`)].find((e) => e.textContent === '编辑');
      assert.ok(button);
      await act(async () => button.click());
    };
    await edit(1);
    await edit(2);
    assert.equal(requests[0].config.signal.aborted, true);
    await act(async () => requests[1].resolve({ data: { id: 2, title: '服务端最新标题 B', article_category_id: 1, is_published: true } }));
    await act(async () => requests[0].resolve({ data: { id: 1, title: '服务端过期标题 A', article_category_id: 1, is_published: true } }));
    const drawerTitle = () => document.querySelector('.ant-drawer [id$="_title"]');
    assert.ok(drawerTitle());
    assert.equal(drawerTitle().value, '服务端最新标题 B');
  } finally {
    for (const request of requests) request.resolve({ data: {} });
    await view.close();
  }
});

test('multi-selecting products applies a unified description in one batch request', {timeout:15000}, async () => {
  const batchWrites = [];
  let listCalls = 0;
  globalThis.reviewApi = {
    getCategories: async () => ({ data: { data: [{ id: 1, name: '甲骨文免费号' }, { id: 2, name: '甲骨文按量付费号' }] } }),
    getProducts: async () => {
      listCalls++;
      return {
        data: {
          data: [
            { id: 11, name: '甲骨文免费号 · 日本东京（ap-tokyo-1）', category_id: 1, price: '50.00', stock_count: 3, is_active: true },
            { id: 12, name: '甲骨文免费号 · 韩国首尔（ap-seoul-1）', category_id: 1, price: '50.00', stock_count: 5, is_active: true },
            { id: 13, name: '甲骨文按量付费号 · 美国圣何塞（us-sanjose-1）', category_id: 2, price: '180.00', stock_count: 2, is_active: true },
          ],
          total: 3,
        },
      };
    },
    getProduct: async (id) => ({ data: { id, name: '甲骨文免费号 · 日本东京（ap-tokyo-1）', description: '<p>原模板介绍</p>' } }),
    batchUpdateProductDescription: async (ids, description) => {
      batchWrites.push([ids, description]);
      return { data: { message: '已成功统一修改 2 个商品的商品介绍。', count: 2 } };
    },
  };
  const view = await mount('/products');
  try {
    await settleTables();
    assert.ok(!text().includes('批量修改介绍'));
    const checkRow = async (id) => {
      const cb = document.querySelector(`tr[data-row-key="${id}"] input[type="checkbox"]`);
      assert.ok(cb, `Missing checkbox for product ${id}`);
      await act(async () => cb.click());
    };
    await checkRow(11);
    await checkRow(12);
    assert.ok(text().includes('批量修改介绍（2）'));
    await click('批量修改介绍（2）');
    assert.ok(document.querySelector('[role="dialog"]'));
    await click('载入首个已选商品当前介绍');
    await settleTables();
    const editor = document.querySelector('[role="dialog"] [data-testid="rich-editor"]');
    assert.ok(editor);
    assert.equal(editor.value, '<p>原模板介绍</p>');
    await act(async () => {
      Object.getOwnPropertyDescriptor(window.HTMLTextAreaElement.prototype, 'value').set.call(editor, '<p>统一更新后的甲骨文账号交付与质保说明</p>');
      editor.dispatchEvent(new window.Event('input', { bubbles: true }));
      editor.dispatchEvent(new window.Event('change', { bubbles: true }));
    });
    const confirmBtn = [...document.querySelectorAll('[role="dialog"] button')].find((b) => ['确定', '确认'].includes(b.textContent.replace(/\s+/g, '')));
    assert.ok(confirmBtn);
    await act(async () => confirmBtn.click());
    await settleTables();
    assert.deepEqual(batchWrites, [[[11, 12], '<p>统一更新后的甲骨文账号交付与质保说明</p>']]);
    assert.ok(!text().includes('批量修改介绍（2）'));
    assert.ok(listCalls >= 2);
  } finally {
    await view.close();
  }
});

test('multi-selecting products adjusts prices in one batch request with live preview', {timeout:15000}, async () => {
  const batchPriceWrites = [];
  let listCalls = 0;
  globalThis.reviewApi = {
    getCategories: async () => ({ data: { data: [{ id: 1, name: '亚太区' }] } }),
    getProducts: async () => {
      listCalls++;
      return {
        data: {
          data: [
            { id: 21, name: '东京升级 - 高质量谷歌', category_id: 1, price: '200.00', stock_count: 10, is_active: true },
            { id: 22, name: '大阪升级 - 高质量谷歌', category_id: 1, price: '180.00', stock_count: 8, is_active: true },
          ],
          total: 2,
        },
      };
    },
    batchUpdateProductPrice: async (ids, payload) => {
      batchPriceWrites.push([ids, payload]);
      return { data: { message: '已成功调整 2 个商品的销售价格。', count: 2, wholesale_updated_count: 4 } };
    },
  };
  const view = await mount('/products');
  try {
    await settleTables();
    assert.ok(!text().includes('批量修改价格'));
    for (const id of [21, 22]) {
      const cb = document.querySelector(`tr[data-row-key="${id}"] input[type="checkbox"]`);
      assert.ok(cb, `Missing checkbox for product ${id}`);
      await act(async () => cb.click());
    }
    assert.ok(text().includes('批量修改价格（2）'));
    await click('批量修改价格（2）');
    const dialog = document.querySelector('[role="dialog"]');
    assert.ok(dialog);
    const percentOption = [...dialog.querySelectorAll('label')].find((el) => el.textContent.replace(/\s+/g, '') === '按百分比调价');
    assert.ok(percentOption, 'Missing percent pricing mode option');
    await act(async () => percentOption.click());
    const valueInput = dialog.querySelector('input[role="spinbutton"]');
    assert.ok(valueInput, 'Missing price adjustment input');
    await act(async () => {
      Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set.call(valueInput, '-10');
      valueInput.dispatchEvent(new window.Event('input', { bubbles: true }));
      valueInput.dispatchEvent(new window.Event('change', { bubbles: true }));
    });
    await settleTables();
    const dialogText = dialog.textContent.replace(/\s+/g, '');
    assert.ok(dialogText.includes('¥200.00→¥180.00'), `Expected live preview for product 21, got: ${dialogText}`);
    assert.ok(dialogText.includes('¥180.00→¥162.00'), `Expected live preview for product 22, got: ${dialogText}`);
    const confirmBtn = [...dialog.querySelectorAll('button')].find((b) => ['确定', '确认'].includes(b.textContent.replace(/\s+/g, '')));
    assert.ok(confirmBtn);
    await act(async () => confirmBtn.click());
    await settleTables();
    assert.deepEqual(batchPriceWrites, [[[21, 22], { mode: 'percent', value: -10, wholesale_strategy: 'scale' }]]);
    assert.ok(!text().includes('批量修改价格（2）'));
    assert.ok(listCalls >= 2);
  } finally {
    await view.close();
  }
});

test('telegram notification tab requires explicit save before test push when edits are unsaved', async () => {
  const sequence = [];
  globalThis.reviewApi = {
    getSettings: async () => ({
      data: {
        telegram_enabled: '0',
        telegram_bot_token: '********',
        telegram_chat_id: '123456789',
        telegram_notify_product_sold: '1',
        telegram_notify_product_listed: '1',
        telegram_target_bot_enabled: '1',
        telegram_target_group_enabled: '1',
        telegram_target_group_ids: '-1001234567890#12',
        telegram_target_channel_enabled: '1',
        telegram_target_channel_ids: '@oracle_shop_channel',
        _available_themes: ['default'],
      },
    }),
    updateSettings: async (data) => {
      sequence.push(['save', data]);
      return { data: { message: '设置已保存。' } };
    },
    sendTestTelegram: async (payload) => {
      sequence.push(['test', payload]);
      return { data: { message: '测试通知已全部发送成功' } };
    },
  };
  const view = await mount('/settings');
  try {
    await selectTab('Telegram 通知');
    const panel = activePanel();
    for (const suffix of [
      '_telegram_bot_token',
      '_telegram_chat_id',
      '_telegram_broadcast_bot_token',
      '_telegram_target_bot_chat_ids',
      '_telegram_target_group_ids',
      '_telegram_target_channel_ids',
    ]) {
      assert.ok(panel.querySelector(`[id$="${suffix}"]`), `Missing control ${suffix}`);
    }
    const channelTextarea = panel.querySelector('[id$="_telegram_target_channel_ids"]');
    assert.equal(channelTextarea.value, '@oracle_shop_channel');
    await act(async () => {
      Object.getOwnPropertyDescriptor(window.HTMLTextAreaElement.prototype, 'value').set.call(channelTextarea, '@oracle_shop_channel, -1009988776655');
      channelTextarea.dispatchEvent(new window.Event('input', { bubbles: true }));
    });
    // Clicking test push before saving must NOT auto-save or send with stale settings.
    await click('发送测试通知');
    await settleTables();
    assert.deepEqual(sequence, []);
    // After explicitly clicking Save, test push proceeds cleanly.
    await click('保存设置');
    await settleTables();
    await click('发送测试通知');
    await settleTables();
    assert.deepEqual(sequence, [
      ['save', { telegram_target_channel_ids: '@oracle_shop_channel, -1009988776655' }],
      ['test', { scope: 'auto' }],
    ]);
  } finally {
    await view.close();
  }
});

test('all settings form inputs disable browser autofill and avoid native password fields', async () => {
  globalThis.reviewApi = {
    getSettings: async () => ({
      data: {
        site_name: 'CardShop',
        mail_password: '********',
        telegram_bot_token: '********',
        _available_themes: ['default'],
      },
    }),
  };
  const view = await mount('/settings');
  try {
    const form = document.querySelector('form');
    assert.ok(form);
    assert.equal(form.getAttribute('autocomplete'), 'off');

    const tabs = ['基本设置', 'SEO 设置', '支付设置', '邮件设置', 'Telegram 通知', '交易与售后', '安全与风控', '自动备份'];
    for (const label of tabs) {
      await selectTab(label);
      const panel = activePanel();
      assert.equal(panel.querySelectorAll('input[type="password"]').length, 0, `${label} must not render native password inputs`);
      const editable = [...panel.querySelectorAll('input:not([type="checkbox"]):not([type="radio"]):not([type="file"]):not([type="search"]):not([role="combobox"]), textarea')]
        .filter((el) => !el.getAttribute('data-testid'));
      assert.ok(editable.length > 0, `${label} should contain editable inputs`);
      for (const el of editable) {
        assert.equal(el.getAttribute('autocomplete'), 'off', `${label} -> ${el.id || el.placeholder} missing autocomplete="off"`);
        assert.equal(el.getAttribute('data-lpignore'), 'true', `${label} -> ${el.id || el.placeholder} missing data-lpignore="true"`);
        assert.equal(el.getAttribute('data-1p-ignore'), 'true', `${label} -> ${el.id || el.placeholder} missing data-1p-ignore="true"`);
        assert.equal(el.getAttribute('data-bwignore'), 'true', `${label} -> ${el.id || el.placeholder} missing data-bwignore="true"`);
      }
    }

    await selectTab('Telegram 通知');
    const tokenInput = activePanel().querySelector('[id$="_telegram_bot_token"]');
    assert.ok(tokenInput);
    assert.equal(tokenInput.getAttribute('type'), 'text');
    const wrapper = tokenInput.closest('.ant-input-affix-wrapper');
    assert.ok(wrapper.classList.contains('admin-secret-masked'));
    const toggleBtn = wrapper.querySelector('button.admin-secret-toggle');
    assert.ok(toggleBtn);
    assert.equal(toggleBtn.getAttribute('type'), 'button');
    assert.equal(toggleBtn.getAttribute('aria-pressed'), 'false');
    await act(async () => { toggleBtn.click(); });
    assert.equal(toggleBtn.getAttribute('aria-pressed'), 'true');
    assert.ok(!wrapper.classList.contains('admin-secret-masked'));
  } finally {
    await view.close();
  }
});

test('security tab deep link and one-click honeypot IP/CIDR whitelist buttons require explicit save', async () => {
  const writes = [];
  globalThis.reviewApi = {
    getSettings: async () => ({
      data: {
        honeypot_enabled: '1',
        honeypot_whitelist: '198.51.100.10',
        _available_themes: ['default'],
        _client_ip: '2001:db8:abcd::55',
        _client_subnet64: '2001:db8:abcd::/64',
      },
    }),
    updateSettings: async (data) => {
      writes.push(data);
      return { data: { message: '设置已保存。' } };
    },
  };
  const view = await mount('/settings?tab=security');
  try {
    const panel = activePanel();
    for (const suffix of [
      '_blacklist_enabled',
      '_cross_site_guard_enabled',
      '_form_honeypot_enabled',
      '_turnstile_enabled',
      '_checkout_require_key',
      '_order_limit_enabled',
      '_honeypot_enabled',
    ]) {
      assert.ok(panel.querySelector(`[id$="${suffix}"]`), `Missing security toggle ${suffix}`);
    }
    const textarea = panel.querySelector('[id$="_honeypot_whitelist"]');
    assert.ok(textarea, 'Deep link /settings?tab=security should open Security tab directly');
    assert.ok(panel.textContent.includes('2001:db8:abcd::55'));
    await click('一键加入当前IP');
    await click('加入当前IPv6/64网段');
    assert.equal(textarea.value, '198.51.100.10\n2001:db8:abcd::55\n2001:db8:abcd::/64');
    assert.deepEqual(writes, []);
    await click('保存设置');
    await settleTables();
    assert.deepEqual(writes, [{ honeypot_whitelist: '198.51.100.10\n2001:db8:abcd::55\n2001:db8:abcd::/64' }]);
  } finally {
    await view.close();
  }
});

test('backup tab supports single and simultaneous multi-provider cloud storage selection, live connection tests, and explicit save', async () => {
  const typeInto = async (input, value) => {
    await act(async () => {
      Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set.call(input, value);
      input.dispatchEvent(new window.Event('input', { bubbles: true }));
    });
  };
  const writes = [];
  const tested = [];
  globalThis.reviewApi = {
    getSettings: async () => ({
      data: {
        backup_auto_enabled: '1',
        backup_r2_enabled: '0',
        backup_s3_enabled: '0',
        backup_oss_enabled: '0',
        backup_cos_enabled: '0',
        _available_themes: ['default'],
      },
    }),
    updateSettings: async (data) => {
      writes.push(data);
      return { data: { message: '设置已保存。' } };
    },
    testBackupStorage: async (data) => {
      tested.push(data);
      return { data: { message: `${data.provider} 连接测试通过` } };
    },
  };
  const view = await mount('/settings?tab=backup');
  try {
    const panel = activePanel();
    const checkboxFor = (val) => panel.querySelector(`input[type="checkbox"][value="${val}"]`);
    for (const key of ['r2', 's3', 'oss', 'cos']) {
      assert.ok(checkboxFor(key), `Missing cloud backup checkbox ${key}`);
    }

    // Select Cloudflare R2 and Aliyun OSS simultaneously.
    await act(async () => { checkboxFor('r2').click(); });
    await act(async () => { checkboxFor('oss').click(); });
    assert.ok(panel.textContent.includes('已选 2 个目标'));
    assert.equal(panel.querySelectorAll('input[type="password"]').length, 0, 'Cloud backup secrets must not render native password inputs');

    const r2Bucket = panel.querySelector('[id$="_backup_r2_bucket"]');
    const r2Endpoint = panel.querySelector('[id$="_backup_r2_endpoint"]');
    const r2Ak = panel.querySelector('[id$="_backup_r2_access_key"]');
    const r2Sk = panel.querySelector('[id$="_backup_r2_secret_key"]');
    assert.ok(r2Bucket && r2Endpoint && r2Ak && r2Sk);
    assert.equal(r2Sk.getAttribute('autocomplete'), 'off');
    assert.ok(r2Sk.closest('.ant-input-affix-wrapper')?.classList.contains('admin-secret-masked'));

    const ossBucket = panel.querySelector('[id$="_backup_oss_bucket"]');
    const ossRegion = panel.querySelector('[id$="_backup_oss_region"]');
    assert.ok(ossBucket && ossRegion);

    // Fill R2 values and click its test button BEFORE saving (zero auto-save!).
    await typeInto(r2Bucket, 'shop-r2');
    await typeInto(r2Endpoint, 'https://acct.r2.cloudflarestorage.com');
    await typeInto(r2Ak, 'r2-ak');
    await typeInto(r2Sk, 'r2-sk');
    assert.deepEqual(writes, []);

    const testButtons = [...panel.querySelectorAll('button')].filter((b) => b.textContent.replace(/\s+/g, '') === '测试连接');
    assert.equal(testButtons.length, 2);
    await act(async () => { testButtons[0].click(); });
    await settleTables();
    assert.equal(tested.length, 1);
    assert.equal(tested[0].provider, 'r2');
    assert.equal(tested[0].bucket, 'shop-r2');
    assert.equal(tested[0].endpoint, 'https://acct.r2.cloudflarestorage.com');
    assert.equal(tested[0].secret_key, 'r2-sk');
    assert.deepEqual(writes, []);

    // Explicit save sends both selected cloud providers and their fields.
    await typeInto(ossBucket, 'shop-oss');
    await typeInto(ossRegion, 'cn-hangzhou');
    await click('保存设置');
    await settleTables();
    assert.equal(writes.length, 1);
    assert.equal(writes[0].backup_r2_enabled, true);
    assert.equal(writes[0].backup_oss_enabled, true);
    assert.equal(writes[0].backup_r2_bucket, 'shop-r2');
    assert.equal(writes[0].backup_oss_bucket, 'shop-oss');
    assert.equal(writes[0].backup_oss_region, 'cn-hangzhou');
  } finally {
    await view.close();
  }
});

test('operations backup tab supports one-click local restore, cloud backup pull, and upload modal without native password autofill', async () => {
  const typeInto = async (input, value) => {
    await act(async () => {
      Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set.call(input, value);
      input.dispatchEvent(new window.Event('input', { bubbles: true }));
    });
  };
  const posts = [];
  const gets = [];
  globalThis.reviewApi = {
    get: async (path, config) => {
      gets.push([path, config?.params || null]);
      if (path === '/maintenance/health') {
        return { data: { healthy: true, backups: { healthy: true } } };
      }
      if (path === '/maintenance/backup-runs') {
        return { data: { data: [], total: 0 } };
      }
      if (path === '/maintenance/backups') {
        return {
          data: {
            data: [{ name: 'shop-20261125-020000-local.tar.gz', size: 204800, created_at: '2026-11-25T02:00:00+08:00' }],
            health: { enabled: true, healthy: true, pending: 0, worker_required: true, worker_healthy: true },
            restore: null,
            enabled_cloud_providers: [{ key: 'r2', label: 'Cloudflare R2' }],
          },
        };
      }
      if (path === '/maintenance/cloud-backups') {
        return {
          data: {
            provider: 'r2',
            label: 'Cloudflare R2',
            data: [{ key: 'daily/shop-20261125-030000-cloud.tar.gz', name: 'shop-20261125-030000-cloud.tar.gz', size: 307200, last_modified: '2026-11-25T03:00:05Z' }],
          },
        };
      }
      if (path === '/maintenance/restore-status') {
        return {
          data: {
            restore: { status: 'completed', progress: 100, filename: 'shop-20261125-020000-local.tar.gz', source: 'local', phase: '全量恢复已完成' },
          },
        };
      }
      return { data: { data: [] } };
    },
    post: async (path, data) => {
      posts.push([path, data]);
      if (path.endsWith('/restore')) {
        return {
          data: {
            message: '恢复任务已提交',
            restore: { status: 'pending', progress: 5, filename: 'shop-20261125-020000-local.tar.gz', source: 'local', phase: '等待备份进程接管恢复任务' },
          },
        };
      }
      if (path === '/maintenance/cloud-backups/pull') {
        return { data: { message: '已从 Cloudflare R2 拉取并校验通过' } };
      }
      return { data: { message: 'ok' } };
    },
  };

  const view = await mount('/operations');
  try {
    await selectTab('备份与恢复');
    await settleTables();

    assert.ok(gets.some(([p, params]) => p === '/maintenance/cloud-backups' && params?.provider === 'r2'));
    assert.ok(text().includes('shop-20261125-020000-local.tar.gz'));
    assert.ok(text().includes('shop-20261125-030000-cloud.tar.gz'));

    // Pull from cloud without immediately restoring
    await click('拉取到本地');
    await settleTables();
    assert.ok(posts.some(([p, d]) => p === '/maintenance/cloud-backups/pull' && d.provider === 'r2' && d.key === 'daily/shop-20261125-030000-cloud.tar.gz' && d.restore_now === false));

    // Open Upload modal and verify file input + zero native password input
    await click('上传备份包');
    await settleTables();
    assert.ok(document.querySelector('#backup-archive-upload-input'));
    assert.equal(document.querySelectorAll('input[type="password"]').length, 0);
    await click('取消');
    await settleTables();

    // Trigger local one-click restore modal, verify masked non-autofill password input, submit
    await click('一键还原');
    await settleTables();
    const pwdInput = document.querySelector('#restore-confirm-password');
    assert.ok(pwdInput, 'Restore confirmation modal must render #restore-confirm-password');
    assert.equal(pwdInput.getAttribute('type'), 'text');
    assert.equal(pwdInput.getAttribute('autocomplete'), 'off');
    assert.equal(document.querySelectorAll('input[type="password"]').length, 0);
    assert.ok(pwdInput.closest('.ant-input-affix-wrapper')?.classList.contains('admin-secret-masked'));

    await typeInto(pwdInput, 'admin-secret-pass');
    await click('确认立即还原');
    await settleTables();
    assert.ok(posts.some(([p, d]) =>
      p === '/maintenance/backups/shop-20261125-020000-local.tar.gz/restore'
      && d.password === 'admin-secret-pass'
      && d.confirm === 'RESTORE'
    ));
    assert.ok(text().includes('全量恢复任务已入队'));
  } finally {
    await view.close();
  }
});
