import test from 'node:test';
import assert from 'node:assert/strict';
import { initializationStates, initializationBlocksClosing, initializationErrors } from '../src/utils/paymentInitialization.js';

test('gateway creation is separate from collected payment and closing is blocked only while the outcome is unknown', () => {
  assert.equal(initializationStates.uncertain.text, '创建结果待核对');
  assert.equal(initializationStates.succeeded.text, '已创建收银台');
  assert.equal(initializationBlocksClosing('uncertain'), true);
  assert.equal(initializationBlocksClosing('processing'), true);
  for (const state of ['created', 'succeeded', 'failed', null]) assert.equal(initializationBlocksClosing(state), false);
  assert.match(initializationErrors.gateway_uncertain, /尚不能确认/);
});
