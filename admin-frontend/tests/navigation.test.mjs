import test from 'node:test';
import assert from 'node:assert/strict';
import { build } from 'esbuild';
import { mkdir, unlink } from 'node:fs/promises';
import { pathToFileURL, fileURLToPath } from 'node:url';

const directory = fileURLToPath(new URL('../node_modules/.cache/', import.meta.url));
await mkdir(directory, { recursive: true });
const output = `${directory}/navigation-test-${process.pid}.mjs`;

await build({
  entryPoints: [fileURLToPath(new URL('../src/navigation.jsx', import.meta.url))],
  outfile: output,
  bundle: true,
  format: 'esm',
  platform: 'node',
  packages: 'external',
  plugins: [
    {
      name: 'stub-pages',
      setup(b) {
        b.onResolve({ filter: /^\.\/pages\// }, (args) => ({ path: args.path, namespace: 'page-stub' }));
        b.onLoad({ filter: /.*/, namespace: 'page-stub' }, () => ({ contents: 'export default function Page() { return null; }' }));
      },
    },
  ],
});

const { menuTree, leafPaths, resolveRouteState } = await import(pathToFileURL(output));
await unlink(output);

test('sidebar navigation removes standalone category and duplicate account entries', () => {
  assert.equal(leafPaths.includes('/categories'), false);
  assert.equal(leafPaths.includes('/article-categories'), false);
  assert.equal(leafPaths.includes('/notifications'), false);
  assert.equal(leafPaths.includes('/account'), false);
  assert.equal(leafPaths.includes('/products'), true);
  assert.equal(leafPaths.includes('/articles'), true);
  assert.equal(leafPaths.includes('/orders'), true);
  assert.equal(leafPaths.includes('/tasks'), true);
  const tradeGroup = menuTree.route.routes.find((r) => r.path === '/trade');
  assert.equal(tradeGroup.routes[0].path, '/orders');
});

test('resolveRouteState maps detail pages and account route accurately', () => {
  assert.equal(resolveRouteState('/').selectedKey, '/');
  assert.equal(resolveRouteState('/products/42/cards').selectedKey, '/products');
  assert.equal(resolveRouteState('/products/42/cards').meta.title, '卡密库存管理');
  assert.equal(resolveRouteState('/orders/99').selectedKey, '/orders');
  assert.equal(resolveRouteState('/orders/99').meta.title, '订单详情');
  assert.equal(resolveRouteState('/account').selectedKey, '');
  assert.equal(resolveRouteState('/account').meta.title, '账户与安全');
});
