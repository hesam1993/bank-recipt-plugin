const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function fixture() {
    let changes = 0;
    const rows = {
        children: [], listeners: {},
        appendChild(fragment) { this.children.push(fragment.row); },
        get lastElementChild() { return this.children.at(-1); },
        querySelectorAll() { return this.children.map(row => row.button); },
        addEventListener(type, handler) { this.listeners[type] = handler; }
    };
    function row(index) {
        const result = {
            inputs: ['id', 'card', 'bank', 'holder'].map(key => ({ name: `woocommerce_c2c_receipts_accounts[${index}][${key}]`, focus() { this.focused = true; } })),
            querySelector() { return this.inputs[1]; },
            remove() { rows.children.splice(rows.children.indexOf(this), 1); }
        };
        result.button = { hidden: true, closest: () => result };
        return result;
    }
    rows.children.push(row(0));
    const add = { hidden: true, disabled: false, addEventListener(type, callback) { this.click = callback; } };
    const sentinel = { dispatchEvent() { changes++; } };
    const template = { content: { cloneNode() {
        const created = row('__index__');
        return { row: created, querySelectorAll: () => created.inputs };
    } } };
    const root = {
        dataset: { nextIndex: '1' },
        querySelector(selector) { return { '.c2c-account-rows': rows, '.c2c-account-add': add, '.c2c-accounts-empty': sentinel, '.c2c-account-template': template }[selector]; }
    };
    vm.runInNewContext(fs.readFileSync('assets/accounts.js', 'utf8'), { document: { querySelectorAll: () => [root] }, Event, window: {} });
    return { rows, add, get changes() { return changes; } };
}

test('three-field account editor adds uniquely named rows and enables saving', () => {
    const f = fixture();
    assert.equal(f.add.hidden, false);
    f.add.click();
    assert.equal(f.rows.children.length, 2);
    assert.equal(f.changes, 1);
    assert.deepEqual(f.rows.children[1].inputs.map(input => input.name), ['id', 'card', 'bank', 'holder'].map(key => `woocommerce_c2c_receipts_accounts[1][${key}]`));
    assert.equal(f.rows.children[1].inputs[1].focused, true);
    f.rows.listeners.input();
    assert.equal(f.changes, 2);
});

test('removing all accounts and adding again cannot reuse submitted field names', () => {
    const f = fixture();
    f.add.click();
    while (f.rows.children.length) {
        f.rows.listeners.click({ target: { closest: () => f.rows.children[0].button } });
    }
    assert.equal(f.rows.children.length, 0);
    f.add.click();
    assert.match(f.rows.children[0].inputs[1].name, /\[2\]\[card\]$/);
    assert.equal(f.changes, 4);
});

test('account editor enforces the 20-account limit and unlocks after removal', () => {
    const f = fixture();
    for (let index = 0; index < 30; index++) f.add.click();
    assert.equal(f.rows.children.length, 20);
    assert.equal(f.add.disabled, true);
    f.rows.listeners.click({ target: { closest: () => f.rows.children[0].button } });
    assert.equal(f.add.disabled, false);
    f.add.click();
    assert.equal(f.rows.children.length, 20);
});
