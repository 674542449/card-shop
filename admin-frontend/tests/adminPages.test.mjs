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
await build({ stdin: { contents: "export {default as OrderDetail} from './src/pages/OrderDetail.jsx'; export {default as Settings} from './src/pages/Settings.jsx';", resolveDir: fileURLToPath(new URL('../', import.meta.url)), loader: 'jsx' },
  outfile: output, bundle: true, format: 'esm', platform: 'node', packages: 'external', plugins: [{ name: 'fixture-boundaries', setup(b) {
    b.onResolve({ filter: /services\/api$/ }, () => ({ path: 'api', namespace: 'fixture' }));
    b.onResolve({ filter: /components\/(RichTextEditor|ImageUploader)$/ }, () => ({ path: 'editor', namespace: 'fixture' }));
    b.onLoad({ filter: /.*/, namespace: 'fixture' }, args => ({ contents: args.path === 'editor' ? 'export default function Editor(){return null}' :
      `const call = name => (...args) => globalThis.reviewApi[name](...args);
       export const getOrder=call('getOrder'), closeOrder=call('closeOrder'), markPaid=call('markPaid'), resendOrder=call('resendOrder'), getSettings=call('getSettings'), updateSettings=call('updateSettings'), sendTestEmail=call('sendTestEmail');
       export default { post: call('post') };`, loader: 'js' }));
  }}] });
const { OrderDetail, Settings } = await import(pathToFileURL(output));
after(async () => { await unlink(output); dom.window.close(); });
const deferred = () => { let resolve, reject; const promise = new Promise((a, b) => { resolve = a; reject = b; }); return { promise, resolve, reject }; };
const fixtureOrder = id => ({ id, order_no: `DUMMY-ORDER-${id}`, status: 'paid', payment_method: 'alipay', quantity: 1, unit_price: '10.00', total_amount: '10.00', cards: [], refund_balance: { reserved: '0.00', available: '10.00' } });
const text = () => document.body.textContent.replace(/\s+/g, '');
const click = async label => {
  const button = [...document.querySelectorAll('button')].find(element => element.textContent.replace(/\s+/g, '') === label);
  assert.ok(button, `Missing button: ${label}`);
  await act(async () => { button.click(); });
};
async function mount(path) {
  const admin = { role: 'owner', permission_definition: { capabilities: ['orders.replace_cards', 'orders.refund'] } };
  const router = createMemoryRouter([{ element: React.createElement(Outlet, { context: admin }), children: [{ path: '/orders/:id', element: React.createElement(OrderDetail) }, { path: '/settings', element: React.createElement(Settings) }] }], { initialEntries: [path] });
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
