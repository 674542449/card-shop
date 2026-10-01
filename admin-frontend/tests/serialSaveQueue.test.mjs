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
