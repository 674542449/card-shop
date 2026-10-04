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
await build({ stdin: { contents: "export {default as OrderDetail} from './src/pages/OrderDetail.jsx'; export {default as Settings} from './src/pages/Settings.jsx'; export {default as TaskCenter} from './src/pages/TaskCenter.jsx'; export {default as Operations} from './src/pages/Operations.jsx'; export {default as Dashboard} from './src/pages/Dashboard.jsx'; export {default as ProductCards} from './src/pages/ProductCards.jsx';", resolveDir: fileURLToPath(new URL('../', import.meta.url)), loader: 'jsx' },
  outfile: output, bundle: true, format: 'esm', platform: 'node', packages: 'external', plugins: [{ name: 'fixture-boundaries', setup(b) {
    b.onResolve({ filter: /services\/api$/ }, () => ({ path: 'api', namespace: 'fixture' }));
    b.onResolve({ filter: /components\/(RichTextEditor|ImageUploader)$/ }, () => ({ path: 'editor', namespace: 'fixture' }));
    b.onLoad({ filter: /.*/, namespace: 'fixture' }, args => ({ contents: args.path === 'editor' ? 'export default function Editor(){return null}' :
      `const call = name => (...args) => globalThis.reviewApi[name](...args);
       export const getOrder=call('getOrder'), closeOrder=call('closeOrder'), markPaid=call('markPaid'), resendOrder=call('resendOrder'), getSettings=call('getSettings'), updateSettings=call('updateSettings'), sendTestEmail=call('sendTestEmail');
       export const getNotifications=call('getNotifications'), retryNotification=call('retryNotification'), getDashboard=call('getDashboard'), getProductCards=call('getProductCards'), importCards=call('importCards'), deleteCard=call('deleteCard'), batchDeleteCards=call('batchDeleteCards'), setCardStatus=call('setCardStatus');
       export default { get: call('get'), post: call('post') };`, loader: 'js' }));
  }}] });
const { OrderDetail, Settings, TaskCenter, Operations, Dashboard, ProductCards } = await import(pathToFileURL(output));
after(async () => { await unlink(output); dom.window.close(); });
const deferred = () => { let resolve, reject; const promise = new Promise((a, b) => { resolve = a; reject = b; }); return { promise, resolve, reject }; };
const fixtureOrder = id => ({ id, order_no: `DUMMY-ORDER-${id}`, status: 'paid', payment_method: 'alipay', quantity: 1, unit_price: '10.00', total_amount: '10.00', cards: [], refund_balance: { reserved: '0.00', available: '10.00' } });
const text = () => document.body.textContent.replace(/\s+/g, '');
const click = async label => {
  const button = [...document.querySelectorAll('button')].find(element => element.textContent.replace(/\s+/g, '') === label);
  assert.ok(button, `Missing button: ${label}`);
  await act(async () => { button.click(); });
};
const owner = { role: 'owner', permission_definition: { capabilities: ['orders.replace_cards', 'orders.refund', 'reconciliation.read', 'reconciliation.retry', 'orders:read', 'refunds:read', 'notifications:read', 'catalog:read'], pages: [
  {path:'/orders',capability:'orders:read',children:true}, {path:'/refunds',capability:'refunds:read'}, {path:'/tasks',any:['notifications:read','content:read','reconciliation.read']}, {path:'/products',capability:'catalog:read',children:true},
] } };
async function mount(path, admin = owner) {
  const router = createMemoryRouter([{ element: React.createElement(Outlet, { context: admin }), children: [{ path: '/orders/:id', element: React.createElement(OrderDetail) }, { path: '/settings', element: React.createElement(Settings) }, { path: '/tasks', element: React.createElement(TaskCenter) }, { path: '/operations', element: React.createElement(Operations) }, { path: '/', element: React.createElement(Dashboard) }, { path: '/products/:productId/cards', element: React.createElement(ProductCards) }] }], { initialEntries: [path] });
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

test('payment and mail groups expose all existing fields and save only the edited setting', async () => {
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
  } finally { await view.close(); }
  assert.deepEqual(writes, [{epay_api_url:'https://new.example.test'}]);
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

test('notification-only staff cannot mount other queues or see retry controls', async () => {
  const calls = [];
  globalThis.reviewApi = {getNotifications: async () => {calls.push('notifications'); return {data:{data:[{id:1,status:'failed',type:'order_email'}],total:1}};}, get: async path => {calls.push(path); throw new Error('Unauthorized module requested');}};
  const view = await mount('/tasks?tab=seo', {role:'staff',permissions:['notifications:read'],permission_definition:{capabilities:['notifications:read']}});
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
