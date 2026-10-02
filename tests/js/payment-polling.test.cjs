const {test} = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const code = fs.readFileSync('public/js/front.js', 'utf8');
const flush = async () => { for (let i = 0; i < 12; i++) await Promise.resolve(); };
function page(responses, lifetime = 60 * 60000) {
    let now = 0, nextId = 0, reloads = 0, calls = 0;
    const timers = new Map();
    const status = {textContent: ''};
    const verify = {hidden: true};
    const retry = {disabled: false, addEventListener: (_, fn) => { retry.click = fn; }};
    const polling = {dataset: {orderNo: 'DUMMY', expires: new Date(lifetime).toISOString()}, querySelector: sel => ({'[data-payment-status]':status, '[data-payment-recheck]':retry, '[data-payment-verify]':verify})[sel]};
    class ClockDate extends Date { static now() { return now; } }
    const document = {getElementById: id => id === 'payment-polling' ? polling : null, querySelectorAll: () => [], addEventListener: (event, fn) => { if (event === 'DOMContentLoaded') document.start = fn; }};
    const context = {document, window:{addEventListener(){}, location:{reload(){reloads++;}}}, navigator:{}, Date:ClockDate, AbortController, setTimeout:(fn, delay) => { timers.set(++nextId, {fn, due:now+delay}); return nextId; }, clearTimeout:id => timers.delete(id), setInterval(){}, clearInterval(){}, fetch: async () => { calls++; const value = responses.shift(); if (value instanceof Error) throw value; return {ok:(value.http || 200) === 200, status:value.http || 200, redirected:false, json:async () => value}; }};
    vm.runInNewContext(code, context); document.start();
    return {status, verify, retry, timers, get reloads(){return reloads;}, get calls(){return calls;}, async tick(time){now=time; const due=[...timers].filter(([,t])=>t.due<=now); for(const [id,t] of due){timers.delete(id);t.fn();} await flush();}, async manual(){retry.click();await flush();}};
}
test('an order valid for an hour still detects payment after thirty minutes', async () => {
    const p=page([{status:'pending'}, {status:'pending'}, {status:'paid'}]); await flush();
    await p.tick(31*60000); assert.equal(p.calls,2); assert.equal(p.reloads,0);
    await p.tick(31*60000+5000); assert.equal(p.reloads,1);
});
test('network failure is visible and manual recheck recovers', async () => {
    const p=page([new Error('offline'), {status:'paid'}]); await flush();
    assert.match(p.status.textContent,/暂时无法/); assert.equal(p.retry.disabled,false);
    await p.manual(); assert.equal(p.reloads,1);
});
test('expired buyer session stops automatic checks and exposes verification', async () => {
    const p=page([{status:'paid',verification_required:true}]); await flush();
    assert.equal(p.verify.hidden,false); assert.match(p.status.textContent,/验证/);
    assert.equal(p.timers.size,0); assert.equal(p.reloads,0);
});
test('unauthorized response explains how to resume order lookup', async () => {
    const p=page([{http:419}]); await flush(); assert.equal(p.verify.hidden,false); assert.match(p.status.textContent,/会话/);
});
test('a failed check beyond the deadline stops and remains manually recoverable', async () => {
    const p=page([{status:'pending'},new Error('offline'),{status:'paid'}],60000); await flush();
    await p.tick(61000); assert.match(p.status.textContent,/截止/); assert.equal(p.timers.size,0);
    await p.manual(); assert.equal(p.reloads,1);
});
