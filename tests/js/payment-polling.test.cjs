const {test} = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const code = fs.readFileSync('public/js/front.js', 'utf8');
const flush = async () => { for (let i = 0; i < 12; i++) await Promise.resolve(); };
function page(responses, lifetime = 60 * 60000, datasetOverrides = {}) {
    let now = 0, nextId = 0, reloads = 0, calls = 0;
    const fetchedUrls = [];
    const timers = new Map();
    const status = {textContent: ''};
    const verify = {hidden: true};
    const retry = {disabled: false, addEventListener: (_, fn) => { retry.click = fn; }};
    const polling = {dataset: {orderNo: 'DUMMY', expires: new Date(lifetime).toISOString(), ...datasetOverrides}, querySelector: sel => ({'[data-payment-status]':status, '[data-payment-recheck]':retry, '[data-payment-verify]':verify})[sel]};
    class ClockDate extends Date { static now() { return now; } }
    const document = {getElementById: id => id === 'payment-polling' ? polling : null, querySelectorAll: () => [], addEventListener: (event, fn) => { if (event === 'DOMContentLoaded') document.start = fn; }};
    const context = {document, window:{addEventListener(){}, location:{reload(){reloads++;}}}, navigator:{}, Date:ClockDate, AbortController, setTimeout:(fn, delay) => { timers.set(++nextId, {fn, due:now+delay}); return nextId; }, clearTimeout:id => timers.delete(id), setInterval(){}, clearInterval(){}, fetch: async (url) => { calls++; fetchedUrls.push(url); const value = responses.shift(); if (value instanceof Error) throw value; return {ok:(value.http || 200) === 200, status:value.http || 200, redirected:false, json:async () => value}; }};
    vm.runInNewContext(code, context); document.start();
    return {status, verify, retry, timers, fetchedUrls, get reloads(){return reloads;}, get calls(){return calls;}, async tick(time){now=time; const due=[...timers].filter(([,t])=>t.due<=now); for(const [id,t] of due){timers.delete(id);t.fn();} await flush();}, async manual(){retry.click();await flush();}};
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
test('uses signed data-poll-url when provided for cross-device polling', async () => {
    const p=page([{status:'pending'}], 60000, {pollUrl: '/order/pay/DUMMY?sig=0123456789abcdef0123456789abcdef'});
    await flush();
    assert.deepEqual(p.fetchedUrls, ['/order/pay/DUMMY?sig=0123456789abcdef0123456789abcdef']);
});
test('403 permission denied stops automatic polling and exposes verification', async () => {
    const p=page([{http:403}]); await flush();
    assert.equal(p.verify.hidden,false); assert.match(p.status.textContent,/验证/);
    assert.equal(p.timers.size,0); assert.equal(p.reloads,0);
});
test('cross-device paid status stops countdown, hides payment action, and states order is paid', async () => {
    let now = 0, nextId = 0, clearedIntervals = 0;
    const timers = new Map();
    const status = {textContent: ''};
    const verify = {hidden: true};
    const retry = {disabled: false, addEventListener: () => {}};
    const payAction = {hidden: false, attrs: {}, setAttribute(k, v) { this.attrs[k] = v; }};
    const timeEl = {textContent: '10:00'};
    const countdown = {dataset: {expires: new Date(600000).toISOString()}, querySelector: () => timeEl};
    const polling = {dataset: {orderNo: 'DUMMY', expires: new Date(600000).toISOString()}, querySelector: sel => ({'[data-payment-status]':status, '[data-payment-recheck]':retry, '[data-payment-verify]':verify})[sel]};
    class ClockDate extends Date { static now() { return now; } }
    const document = {
        getElementById: id => id === 'payment-polling' ? polling : (id === 'countdown-timer' ? countdown : null),
        querySelectorAll: sel => sel === '[data-payment-action]' ? [payAction] : [],
        addEventListener: (event, fn) => { if (event === 'DOMContentLoaded') document.start = fn; },
    };
    const context = {
        document,
        window: {addEventListener(){}, location:{reload(){}}},
        navigator: {},
        Date: ClockDate,
        AbortController,
        setTimeout: (fn, delay) => { timers.set(++nextId, {fn, due: now + delay}); return nextId; },
        clearTimeout: id => timers.delete(id),
        setInterval: () => 99,
        clearInterval: () => { clearedIntervals++; },
        fetch: async () => ({ok: true, status: 200, redirected: false, json: async () => ({status: 'paid', verification_required: true})}),
    };
    vm.runInNewContext(code, context);
    document.start();
    await flush();
    assert.equal(verify.hidden, false);
    assert.equal(payAction.hidden, true);
    assert.equal(payAction.attrs['aria-disabled'], 'true');
    assert.equal(clearedIntervals, 1);
    assert.equal(timeEl.textContent, '已支付');
    assert.match(status.textContent, /已支付成功/);
    assert.equal(timers.size, 0);
});
test('firefox fallback masks secret inputs via type=password when -webkit-text-security is unsupported', () => {
    const classes = new Set(['is-secret-masked']);
    const secretInput = {
        id: 'query-password',
        type: 'text',
        classList: {
            contains: c => classes.has(c),
            toggle: (c, force) => { if (force) classes.add(c); else classes.delete(c); },
        },
    };
    const toggleAttrs = {'data-secret-toggle': 'query-password'};
    const toggleBtn = {
        textContent: '显示',
        closest: sel => sel === '[data-secret-toggle]' ? toggleBtn : null,
        getAttribute: k => toggleAttrs[k] || null,
        setAttribute: (k, v) => { toggleAttrs[k] = v; },
    };
    const clickHandlers = [];
    const document = {
        getElementById: id => id === 'query-password' ? secretInput : null,
        querySelectorAll: sel => sel === 'input.is-secret-masked' ? [secretInput] : [],
        addEventListener: (event, fn) => {
            if (event === 'DOMContentLoaded') document.start = fn;
            if (event === 'click') clickHandlers.push(fn);
        },
    };
    const context = {
        document,
        window: {CSS: {supports: () => false}, addEventListener(){}, location:{reload(){}}},
        navigator: {},
        Date,
        AbortController,
        setTimeout,
        clearTimeout,
        setInterval,
        clearInterval,
    };
    vm.runInNewContext(code, context);
    document.start();
    assert.equal(secretInput.type, 'password');
    clickHandlers.forEach(fn => fn({target: toggleBtn}));
    assert.equal(secretInput.type, 'text');
    assert.equal(toggleAttrs['aria-pressed'], 'true');
    assert.equal(toggleBtn.textContent, '隐藏');
    clickHandlers.forEach(fn => fn({target: toggleBtn}));
    assert.equal(secretInput.type, 'password');
    assert.equal(toggleAttrs['aria-pressed'], 'false');
    assert.equal(toggleBtn.textContent, '显示');
});
