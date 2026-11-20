const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

test('Blocks registers a real gateway and passes a valid token through paymentMethodData', () => {
    let registered, observer, unsubscribed = false, destroyed = false;
    const refs = [], effects = [];
    const state = { token: '', expires: Date.now() / 1000 + 1000, busy: false, loading: false };
    const window = {
        wp: { element: {
            createElement: (type, props, ...children) => ({ type, props, children }),
            useRef: initial => { const ref = { current: initial }; refs.push(ref); return ref; },
            useEffect: effect => effects.push(effect)
        }, htmlEntities: { decodeEntities: value => value } },
        wc: { wcSettings: { getSetting: () => ({ title: 'کارت به کارت', accounts: [{ id: '1' }] }) }, wcBlocksRegistry: { registerPaymentMethod: method => { registered = method; } } },
        C2CReceipts: {
            labels: { required: 'Required' }, build: () => ({ remove() {} }),
            mount: () => ({ state, destroy() { destroyed = true; } })
        }
    };
    vm.runInNewContext(fs.readFileSync('assets/blocks.js', 'utf8'), { window });
    assert.equal(registered.name, 'c2c_receipts');
    assert.equal(registered.canMakePayment(), true);
    registered.content.type({
        eventRegistration: { onPaymentSetup: callback => { observer = callback; return () => { unsubscribed = true; }; } },
        emitResponse: { responseTypes: { SUCCESS: 'success', ERROR: 'error' } }
    });
    refs[0].current = { appendChild() {} };
    const cleanups = effects.map(effect => effect());
    assert.equal(observer().type, 'error');
    state.token = 'a'.repeat(64);
    assert.equal(observer().meta.paymentMethodData.c2c_token, state.token);
    state.busy = true; assert.equal(observer().type, 'error');
    state.busy = false; state.expires = 0; assert.equal(observer().type, 'error');
    cleanups.forEach(cleanup => cleanup());
    assert.equal(unsubscribed, true); assert.equal(destroyed, true);
});
