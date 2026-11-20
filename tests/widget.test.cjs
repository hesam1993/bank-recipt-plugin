const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

class Element {
    constructor(tag) {
        this.tag = tag; this.children = []; this.dataset = {}; this.value = '';
        this.className = ''; this.listeners = {}; this.hidden = false;
    }
    appendChild(node) {
        this.children.push(node);
        if (this.tag === 'select' && this.children.length === 1) this.value = node.value;
        return node;
    }
    replaceChildren() { this.children = []; }
    setAttribute(name, value) { this[name] = value; }
    addEventListener(event, callback) { this.listeners[event] = callback; }
    get options() { return this.children; }
    get selectedOptions() { return this.children.filter(node => node.value === this.value); }
    querySelector(selector) {
        const key = selector.slice(1);
        for (const node of this.children) {
            if (node.className.split(' ').includes(key)) return node;
            const found = node.querySelector(selector); if (found) return found;
        }
        return null;
    }
}
const account = { id: 'bank1', bank: '<img src=x onerror=alert(1)>', holder: 'فروشگاه', card: '0123456789012345' };
function runtime({ saved, fetcher, xhr } = {}) {
    const storage = new Map(Object.entries(saved || {}));
    const calls = [];
    const window = {
        sessionStorage: { getItem: key => storage.get(key), setItem: (key, value) => storage.set(key, value), removeItem: key => storage.delete(key) },
        fetch: async (url, options) => {
            calls.push({ url, options });
            const data = await (fetcher ? fetcher(url) : { nonce: 'csrf' });
            return { ok: !data.error, json: async () => ({ success: !data.error, data: data.error ? { message: data.error } : data }) };
        }, location: { reload() {} }
    };
    const labels = { error: 'Network error', required: 'Receipt required', ready: 'Ready', uploading: 'Uploading', fileError: 'Invalid file', help: 'Max %s MB', account: 'Account', copy: 'Copy' };
    class XHR {
        constructor() { this.upload = {}; }
        open() {}
        send(data) {
            calls.push({ data });
            if (xhr) { xhr(this, data); return; }
            this.status = 200;
            this.responseText = JSON.stringify({ success: true, data: { token: 'a'.repeat(64), expires: Date.now() / 1000 + 86400, amount: '100 IRR' } });
            this.onload();
        }
    }
    vm.runInNewContext(fs.readFileSync('assets/common.js', 'utf8'), {
        window, document: { createElement: tag => new Element(tag) },
        navigator: { clipboard: { writeText: async () => {} } },
        c2cReceiptsConfig: { labels, maxBytes: 5242880, bootUrl: 'boot', statusUrl: 'status', uploadUrl: 'upload' },
        FormData, URL, XMLHttpRequest: XHR, console
    });
    const form = window.C2CReceipts.build([account]);
    const widget = window.C2CReceipts.mount(form);
    return { api: window.C2CReceipts, window, form, widget, storage, calls };
}
const flush = () => new Promise(resolve => setImmediate(resolve));

test('rejects unsupported, empty, oversized and mismatched client file types', () => {
    const { api } = runtime();
    for (const file of [null, { name: 'a.svg', type: 'image/svg+xml', size: 2 }, { name: 'a.php', type: 'image/png', size: 2 }, { name: 'a.png', type: 'image/png', size: 0 }, { name: 'a.jpg', type: 'image/jpeg', size: 5242881 }]) {
        assert.equal(api.validFile(file), false);
    }
    assert.equal(api.validFile({ name: 'a.webp', type: 'image/webp', size: 100 }), true);
});

test('account content is text and preserves leading zeroes', () => {
    const { form } = runtime();
    const select = form.querySelector('.c2c-account');
    assert.equal(select.options[0].textContent, account.bank + ' — ' + account.holder);
    assert.equal(form.querySelector('.c2c-details').children[1].children[0].textContent, account.card);
});

test('an account without an optional bank displays only its holder name', () => {
    const { api } = runtime();
    const form = api.build([{ id: 'persian', bank: '', holder: 'علی رضایی', card: '0123456789012345' }]);
    assert.equal(form.querySelector('.c2c-account').options[0].textContent, 'علی رضایی');
});

test('a successful upload persists only its recoverable token, not image bytes', async () => {
    const { form, widget, storage, calls } = runtime();
    const input = form.querySelector('.c2c-file');
    input.files = [new File(['image'], 'receipt.jpg', { type: 'image/jpeg' })];
    input.listeners.change();
    assert.equal(widget.state.busy, true);
    await flush(); await flush();
    assert.equal(widget.state.busy, false);
    assert.equal(widget.state.token, 'a'.repeat(64));
    const persisted = JSON.parse(storage.get('c2c-receipt-v1-checkout'));
    assert.equal(persisted.token, widget.state.token);
    assert.equal('preview' in persisted, false);
    assert.equal('receipt' in persisted, false);
    assert.equal(calls.filter(call => call.url === 'boot').length, 1);
    assert.equal(calls.find(call => call.data)?.data.get('nonce'), 'csrf');
});

test('checkout remounts share upload state and unsubscribe cleanly', async () => {
    let complete;
    const runtimeState = runtime({ xhr: request => { complete = request; } });
    const { api, form, widget } = runtimeState;
    form.querySelector('.c2c-file').files = [new File(['image'], 'receipt.png', { type: 'image/png' })];
    form.querySelector('.c2c-file').listeners.change();
    await flush();
    const second = api.mount(api.build([account]));
    assert.equal(second.state.busy, true);
    widget.destroy();
    complete.status = 200;
    complete.responseText = JSON.stringify({ success: true, data: { token: 'b'.repeat(64), expires: Date.now() / 1000 + 86400 } });
    complete.onload(); await flush();
    assert.equal(second.state.token, 'b'.repeat(64));
    assert.equal(second.state.listeners.size, 1);
});

test('restore verifies the saved token with the server before checkout is allowed', async () => {
    const saved = { 'c2c-receipt-v1-checkout': JSON.stringify({ token: 'c'.repeat(64), expires: Date.now() / 1000 + 1000 }) };
    const { widget } = runtime({ saved, fetcher: async url => url === 'boot' ? { nonce: 'x' } : { amount: '100 IRR', account } });
    assert.equal(widget.state.loading, true);
    await flush(); await flush();
    assert.equal(widget.state.loading, false);
    assert.equal(widget.state.account, account.id);
});

test('invalid saved ownership clears the token and displays the server error', async () => {
    const saved = { 'c2c-receipt-v1-checkout': JSON.stringify({ token: 'c'.repeat(64), expires: Date.now() / 1000 + 1000 }) };
    const { widget, storage } = runtime({ saved, fetcher: async url => {
        if (url === 'boot') return { nonce: 'x' };
        return { error: 'Wrong session' };
    } });
    await flush(); await flush();
    assert.equal(widget.state.token, '');
    assert.equal(widget.state.message, 'Wrong session');
    assert.equal(storage.has('c2c-receipt-v1-checkout'), false);
});

test('changing destination invalidates the previously uploaded receipt token', async () => {
    const { form, widget } = runtime();
    form.querySelector('.c2c-file').files = [new File(['image'], 'receipt.png', { type: 'image/png' })];
    form.querySelector('.c2c-file').listeners.change(); await flush(); await flush();
    form.querySelector('.c2c-account').listeners.change();
    assert.equal(widget.state.token, '');
});

test('upload failures never present a receipt as ready', async () => {
    const { form, widget } = runtime({ xhr: request => { request.onerror(); } });
    form.querySelector('.c2c-file').files = [new File(['image'], 'receipt.png', { type: 'image/png' })];
    form.querySelector('.c2c-file').listeners.change(); await flush(); await flush();
    assert.equal(widget.state.busy, false);
    assert.equal(widget.state.token, '');
    assert.equal(widget.state.message, 'Network error');
});
