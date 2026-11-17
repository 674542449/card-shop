import { test } from 'node:test';
import assert from 'node:assert/strict';
import { adminContextChanged, adminSessionEpoch, onAdminSessionChange, resetAdminSession } from '../src/services/sessionLifecycle.js';
import { createSerialSaveQueue } from '../src/utils/serialSaveQueue.js';
import { createAdminSessionLifecycle } from '../src/services/sessionLifecycle.js';
import { JSDOM } from 'jsdom';
import { BroadcastChannel } from 'node:worker_threads';

test('stale CSV downloads recognize account changes without misclassifying business conflicts', () => {
  const blob = new Blob(['{"code":"admin_context_changed"}'], { type: 'application/json' });
  assert.equal(adminContextChanged({ status: 409, data: blob, headers: { 'x-admin-context': 'new-account' } }, 'old-account'), true);
  assert.equal(adminContextChanged({ status: 409, data: { code: 'admin_context_changed' } }), true);
  assert.equal(adminContextChanged({ status: 409, data: blob, headers: { 'x-admin-context': 'same-account' } }, 'same-account'), false);
  assert.equal(adminContextChanged({ status: 409, data: { code: 'order_conflict' } }), false);
  assert.equal(adminContextChanged({ status: 200, data: blob, headers: { 'x-admin-context': 'new-account' } }, 'old-account'), false);
});

test('login, rejected logout and authentication loss can discard retained private edits', async () => {
  const queue = createSerialSaveQueue(() => Promise.reject(new Error('offline')));
  const stop = onAdminSessionChange(() => queue.reset());
  for (const boundary of ['login attempt', 'logout attempt', 'logout failure', '401 response']) {
    queue.enqueue({ mail_password: 'DUMMY-PRIVATE-' + boundary }, false);
    await queue.flush().catch(() => {});
    const oldEpoch = adminSessionEpoch();
    resetAdminSession();
    assert.equal(adminSessionEpoch(), oldEpoch + 1);
    assert.deepEqual(queue.unsaved(), {});
    assert.equal(queue.pending(), false);
  }
  stop();
});

test('a second document broadcasts a session change and clears the first document queue', async () => {
  const first = new JSDOM('', { url: 'http://127.0.0.1:8002/admin' });
  const second = new JSDOM('', { url: 'http://127.0.0.1:8002/admin' });
  first.window.BroadcastChannel = BroadcastChannel;
  second.window.BroadcastChannel = BroadcastChannel;
  const firstSession = createAdminSessionLifecycle(first.window);
  const secondSession = createAdminSessionLifecycle(second.window);
  const queue = createSerialSaveQueue(() => Promise.reject(new Error('offline')));
  queue.enqueue({ mail_password: 'DUMMY-OLD-OWNER' }, false);
  await queue.flush().catch(() => {});
  const event = new Promise((resolve, reject) => {
    const timeout = setTimeout(() => reject(new Error('missing cross-document event')), 2000);
    firstSession.subscribe(({ remote }) => {
      if (remote) { queue.reset(); clearTimeout(timeout); resolve(); }
    });
  });
  secondSession.reset();
  await event;
  assert.deepEqual(queue.unsaved(), {});
  assert.equal(queue.pending(), false);
  firstSession.dispose(); secondSession.dispose();
  first.window.close(); second.window.close();
});

test('storage fallback and focus recover a missed event without broadcasting a loop', () => {
  const dom = new JSDOM('', { url: 'http://127.0.0.1:8002/admin' });
  const lifecycle = createAdminSessionLifecycle(dom.window);
  let changes = 0;
  lifecycle.subscribe(({ remote }) => { if (remote) changes += 1; });
  const key = 'cardshop-admin-session-change';
  const message = JSON.stringify({ nonce: 'dummy-other-document' });
  dom.window.localStorage.setItem(key, message);
  dom.window.dispatchEvent(new dom.window.Event('focus'));
  dom.window.dispatchEvent(new dom.window.StorageEvent('storage', { key, newValue: message }));
  assert.equal(changes, 1);
  lifecycle.dispose(); dom.window.close();
});
