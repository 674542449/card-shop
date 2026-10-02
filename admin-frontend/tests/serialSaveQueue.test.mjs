import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createSerialSaveQueue } from '../src/utils/serialSaveQueue.js';

test('navigation flush serializes old/new values and tracks in-flight work', async () => {
  const requests = [];
  const saver = createSerialSaveQueue((data) => new Promise(resolve => requests.push({ data, resolve })));
  saver.enqueue({ site_name: 'old' }, false);
  const first = saver.flush();
  await Promise.resolve();
  assert.equal(saver.pending(), true);
  saver.enqueue({ site_name: 'new' }, false);
  assert.equal(saver.flush(), first);
  assert.equal(requests.length, 1);
  requests[0].resolve();
  await Promise.resolve();
  assert.equal(requests.length, 2);
  assert.deepEqual(requests[1].data, { site_name: 'new' });
  requests[1].resolve();
  await first;
  assert.equal(saver.pending(), false);
});

test('failure retains the latest edit and retry cannot restore a stale value', async () => {
  let rejectFirst;
  const writes = [];
  const saver = createSerialSaveQueue(data => {
    writes.push(data);
    return writes.length === 1 ? new Promise((resolve, reject) => { rejectFirst = reject; }) : Promise.resolve();
  });
  saver.enqueue({ site_name: 'old', site_description: 'retained' }, false);
  const first = saver.flush();
  await Promise.resolve();
  saver.enqueue({ site_name: 'new' }, false);
  rejectFirst(new Error('offline'));
  await assert.rejects(first);
  assert.equal(saver.pending(), true);
  await saver.flush();
  assert.deepEqual(writes[1], { site_name: 'new', site_description: 'retained' });
  assert.equal(saver.pending(), false);
});

test('an immediate throwing transport preserves data for retry', async () => {
  const saver = createSerialSaveQueue(() => { throw new Error('offline'); });
  saver.enqueue({ mail_host: 'smtp.example.test' }, false);
  await assert.rejects(saver.flush());
  assert.deepEqual(saver.unsaved(), { mail_host: 'smtp.example.test' });
});

test('a session reset drops owner credentials and aborts in-flight saving', async () => {
  let rejectOld;
  let oldSignal;
  const saver = createSerialSaveQueue((data, options) => {
    oldSignal = options.signal;
    return new Promise((resolve, reject) => { rejectOld = reject; });
  });
  saver.enqueue({ mail_password: 'DUMMY-OWNER-SECRET' }, false);
  const oldRequest = saver.flush();
  await Promise.resolve();
  saver.reset();
  assert.equal(oldSignal.aborted, true);
  assert.deepEqual(saver.unsaved(), {});
  assert.equal(saver.pending(), false);
  rejectOld(new Error('late transport rejection'));
  await assert.rejects(oldRequest);
  assert.deepEqual(saver.unsaved(), {});
  assert.equal(saver.pending(), false);
});

test('an old request cannot drain or clear a new account queue after reset', async () => {
  let finishOld;
  let finishNew;
  const writes = [];
  const saver = createSerialSaveQueue(data => {
    writes.push(data);
    return new Promise(resolve => {
      if (writes.length === 1) finishOld = resolve;
      else finishNew = resolve;
    });
  });
  saver.enqueue({ site_name: 'old owner' }, false);
  const oldRequest = saver.flush();
  await Promise.resolve();
  saver.reset();
  saver.enqueue({ site_name: 'new owner' }, false);
  const newRequest = saver.flush();
  await Promise.resolve();
  finishOld();
  await oldRequest;
  assert.equal(saver.pending(), true);
  assert.deepEqual(saver.unsaved(), { site_name: 'new owner' });
  finishNew();
  await newRequest;
  assert.deepEqual(writes, [{ site_name: 'old owner' }, { site_name: 'new owner' }]);
  assert.equal(saver.pending(), false);
});
