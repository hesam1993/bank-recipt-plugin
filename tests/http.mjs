/** Real multipart upload/authentication checks against the disposable CI store. */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const base = process.env.C2C_TEST_URL || 'http://127.0.0.1:8080';
const cookies = new Map();
async function request(path, options = {}, jar = cookies) {
    const headers = new Headers(options.headers);
    headers.set('Origin', base);
    headers.set('Cookie', [...jar].map(([key, value]) => `${key}=${value}`).join('; '));
    const response = await fetch(base + path, { ...options, headers, redirect: 'manual' });
    for (const cookie of response.headers.getSetCookie()) {
        const pair = cookie.split(';')[0];
        const split = pair.indexOf('=');
        jar.set(pair.slice(0, split), pair.slice(split + 1));
    }
    return response;
}
async function json(path, data, jar = cookies) {
    const response = await request(path, { method: 'POST', body: data }, jar);
    return { status: response.status, body: await response.json() };
}
const productId = fs.readFileSync(process.env.C2C_PRODUCT_FILE, 'utf8').trim();
await request('/?add-to-cart=' + productId);
const boot = await json('/?wc-ajax=c2c_boot', new URLSearchParams());
assert.equal(boot.body.success, true);
const nonce = boot.body.data.nonce;
const accounts = JSON.parse(fs.readFileSync(process.env.C2C_ACCOUNTS_FILE, 'utf8'));
const account = accounts[0].id;
function upload(file, csrf = nonce) {
    const data = new FormData();
    data.append('nonce', csrf); data.append('account', account);
    data.append('receipt', file); return data;
}
const image = new File([fs.readFileSync(process.env.C2C_IMAGE_FILE)], 'receipt.png', { type: 'image/png' });
let result = await json('/?wc-ajax=c2c_upload', upload(image));
assert.equal(result.body.success, true, JSON.stringify(result.body));
assert.match(result.body.data.token, /^[a-f0-9]{64}$/);
const token = result.body.data.token;
result = await json('/?wc-ajax=c2c_status', new URLSearchParams({ nonce, token }));
assert.equal(result.body.success, true);
result = await json('/?wc-ajax=c2c_upload', upload(image, 'forged'));
assert.equal(result.status, 403);
result = await json('/?wc-ajax=c2c_upload', upload(new File(['<?php echo 1;'], 'fake.png', { type: 'image/png' })));
assert.equal(result.body.success, false, 'Fake image bytes must be rejected');
result = await json('/?wc-ajax=c2c_upload', upload(new File([fs.readFileSync(process.env.C2C_IMAGE_FILE)], 'image.php', { type: 'image/png' })));
assert.equal(result.body.success, false, 'Invalid file extension must be rejected');
const stranger = new Map();
const strangerBoot = await json('/?wc-ajax=c2c_boot', new URLSearchParams(), stranger);
result = await json('/?wc-ajax=c2c_status', new URLSearchParams({ nonce: strangerBoot.body.data.nonce, token }), stranger);
assert.equal(result.body.success, false, 'Other sessions cannot read token status');
const crossOrigin = await request('/?wc-ajax=c2c_boot', { method: 'POST', headers: { Origin: base } });
assert.equal(crossOrigin.status, 200);
const denied = await fetch(base + '/?wc-ajax=c2c_boot', { method: 'POST', headers: { Origin: 'https://attacker.invalid' } });
assert.equal(denied.status, 400);
const file = await request('/wp-admin/admin-post.php?action=c2c_file&receipt_id=1');
assert.equal(file.status, 403, 'Order ID alone grants no receipt access');

// Exercise the real Blocks Store API → legacy gateway payment_data adapter.
const cart = await request('/?rest_route=/wc/store/v1/cart');
assert.equal(cart.status, 200);
const storeNonce = cart.headers.get('nonce');
assert.ok(storeNonce, 'Store API issues a checkout nonce');
const address = {
    first_name: 'Receipt', last_name: 'Test', company: '', address_1: '123 Test Street', address_2: '',
    city: 'Los Angeles', state: 'CA', postcode: '90001', country: 'US', email: 'receipt@example.test', phone: '5551234567'
};
const checkout = await request('/?rest_route=/wc/store/v1/checkout', {
    method: 'POST', headers: { 'Content-Type': 'application/json', Nonce: storeNonce },
    body: JSON.stringify({
        billing_address: address, shipping_address: address, create_account: false,
        payment_method: 'c2c_receipts', payment_data: [{ key: 'c2c_token', value: token }]
    })
});
const order = await checkout.json();
assert.equal(checkout.status, 200, JSON.stringify(order));
assert.equal(order.status, 'on-hold', 'Real block checkout leaves payment unpaid');
assert.equal(order.payment_result.payment_status, 'success', JSON.stringify(order.payment_result));
const redirect = new URL(order.payment_result.redirect_url);
const confirmation = await request(redirect.pathname + redirect.search);
const html = await confirmation.text();
const receiptLink = html.match(/href="([^"]*action=c2c_file[^"]*)"/);
assert.ok(receiptLink, 'Original checkout session can access its receipt panel');
const receiptURL = new URL(receiptLink[1].replaceAll('&amp;', '&'));
const privateImage = await request(receiptURL.pathname + receiptURL.search);
assert.equal(privateImage.status, 200);
assert.match(privateImage.headers.get('content-type'), /^image\/jpeg/);
assert.match(privateImage.headers.get('cache-control'), /no-store/);
const jpeg = Buffer.from(await privateImage.arrayBuffer());
assert.equal(jpeg.subarray(0, 3).toString('hex'), 'ffd8ff');
assert.ok(Number.isSafeInteger(order.order_id));
const metadata = JSON.parse(execFileSync('wp', ['eval', '$o=wc_get_order(' + order.order_id + '); $r=\\C2CReceipts\\Receipts::get((int)$o->get_meta("_c2c_receipt_id")); echo wp_json_encode(["url"=>wp_upload_dir()["baseurl"]."/c2c-receipts/".$r->filename]);'], { cwd: process.env.C2C_SITE_DIR, encoding: 'utf8' }));
const blobURL = new URL(metadata.url);
assert.match(blobURL.pathname, /\/c2c-receipts\/[a-f0-9]{64}\.c2c$/);
const direct = await fetch(blobURL);
assert.equal(direct.status, 200);
const ciphertext = Buffer.from(await direct.arrayBuffer());
assert.equal(ciphertext.subarray(0, 5).toString('hex'), '4332435201');
assert.notDeepEqual(ciphertext, jpeg, 'A public upload URL cannot reveal the receipt image');
const unauthorizedImage = await request('/wp-admin/admin-post.php?action=c2c_file&receipt_id=' + receiptURL.searchParams.get('receipt_id'), {}, stranger);
assert.equal(unauthorizedImage.status, 403, 'Another session cannot access the claimed receipt by ID');
console.log('Multipart uploads, CSRF/origin, session ownership, real Store API checkout and private-file checks passed.');
