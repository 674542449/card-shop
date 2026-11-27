const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');

const code = fs.readFileSync('public/js/front.js', 'utf8');

function mountDialogPage({ hasCommandFor = false, hasClosedBy = false } = {}) {
    const docListeners = new Map();
    const dialogListeners = new Map();
    let showModalCalls = 0;
    let closeCalls = 0;

    const dialog = {
        id: 'contact-qr-dialog',
        tagName: 'DIALOG',
        open: false,
        getBoundingClientRect: () => ({ left: 100, right: 420, top: 80, bottom: 520 }),
        showModal() {
            showModalCalls++;
            this.open = true;
        },
        close() {
            closeCalls++;
            this.open = false;
        },
        addEventListener(type, fn) {
            if (!dialogListeners.has(type)) dialogListeners.set(type, []);
            dialogListeners.get(type).push(fn);
        },
    };

    const openButton = {
        disabled: false,
        getAttribute(name) {
            if (name === 'commandfor') return 'contact-qr-dialog';
            if (name === 'command') return 'show-modal';
            return null;
        },
        closest(selector) {
            return selector === 'button[commandfor][command]' ? openButton : null;
        },
    };

    const document = {
        documentElement: { getAttribute: () => null, setAttribute() {}, removeAttribute() {} },
        body: { style: {}, prepend() {} },
        getElementById: (id) => (id === 'contact-qr-dialog' ? dialog : null),
        querySelectorAll: (sel) => (sel === 'dialog[closedby="any"]' ? [dialog] : []),
        addEventListener(type, fn) {
            if (!docListeners.has(type)) docListeners.set(type, []);
            docListeners.get(type).push(fn);
        },
    };

    function FakeButton() {}
    if (hasCommandFor) {
        FakeButton.prototype.commandForElement = null;
    }

    function FakeDialog() {}
    if (hasClosedBy) {
        FakeDialog.prototype.closedBy = 'any';
    }

    const context = {
        document,
        window: { addEventListener() {}, location: { reload() {} } },
        navigator: {},
        HTMLButtonElement: FakeButton,
        HTMLDialogElement: FakeDialog,
        Date,
        AbortController,
        setTimeout,
        clearTimeout,
        setInterval,
        clearInterval,
    };

    vm.runInNewContext(code, context);
    for (const fn of docListeners.get('DOMContentLoaded') || []) fn();

    return {
        dialog,
        openButton,
        get showModalCalls() { return showModalCalls; },
        get closeCalls() { return closeCalls; },
        clickOpenButton() {
            for (const fn of docListeners.get('click') || []) {
                fn({ target: openButton });
            }
        },
        pointer(type, clientX, clientY) {
            for (const fn of dialogListeners.get(type) || []) {
                fn({ clientX, clientY });
            }
        },
    };
}

test('invoker command fallback opens modal dialog only when native commandForElement is missing', () => {
    const fallbackPage = mountDialogPage({ hasCommandFor: false, hasClosedBy: true });
    assert.equal(fallbackPage.dialog.open, false);
    fallbackPage.clickOpenButton();
    assert.equal(fallbackPage.showModalCalls, 1);
    assert.equal(fallbackPage.dialog.open, true);

    const nativePage = mountDialogPage({ hasCommandFor: true, hasClosedBy: true });
    nativePage.clickOpenButton();
    assert.equal(nativePage.showModalCalls, 0);
});

test('light-dismiss fallback closes dialog on outside backdrop press+release without dismissing drags or inside clicks', () => {
    const page = mountDialogPage({ hasCommandFor: false, hasClosedBy: false });
    page.clickOpenButton();
    assert.equal(page.dialog.open, true);

    // Click inside dialog bounds -> must stay open
    page.pointer('pointerdown', 200, 200);
    page.pointer('pointerup', 200, 200);
    assert.equal(page.dialog.open, true);
    assert.equal(page.closeCalls, 0);

    // Drag starting inside dialog and releasing outside -> must stay open
    page.pointer('pointerdown', 220, 240);
    page.pointer('pointerup', 10, 10);
    assert.equal(page.dialog.open, true);
    assert.equal(page.closeCalls, 0);

    // Press and release outside dialog bounds (on backdrop) -> closes dialog
    page.pointer('pointerdown', 20, 20);
    page.pointer('pointerup', 25, 25);
    assert.equal(page.dialog.open, false);
    assert.equal(page.closeCalls, 1);
});

test('light-dismiss fallback does not attach duplicate handlers when native closedBy exists', () => {
    const page = mountDialogPage({ hasCommandFor: false, hasClosedBy: true });
    page.clickOpenButton();
    page.pointer('pointerdown', 20, 20);
    page.pointer('pointerup', 20, 20);
    assert.equal(page.closeCalls, 0);
    assert.equal(page.dialog.open, true);
});
