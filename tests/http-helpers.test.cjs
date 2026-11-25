const test = require('node:test');
const assert = require('node:assert/strict');
const { createServer } = require('node:http');

async function withServer(handler, run) {
    const server = createServer(handler);
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    try {
        await run(`http://127.0.0.1:${server.address().port}`);
    } finally {
        await new Promise((resolve, reject) => server.close(error => error ? reject(error) : resolve()));
    }
}

test('WordPress escaped receipt URLs retain the action, receipt ID and signature', async () => {
    const { decodeHtmlAttribute } = await import('./helpers/http.mjs');
    for (const separator of ['&amp;', '&#038;', '&#38;', '&#x26;']) {
        const escaped = `http://localhost/admin-post.php?c2c_order=15${separator}c2c_access=signature${separator}action=c2c_file${separator}receipt_id=7`;
        const decoded = new URL(decodeHtmlAttribute(escaped));
        assert.equal(decoded.hash, '');
        assert.equal(decoded.searchParams.get('c2c_access'), 'signature');
        assert.equal(decoded.searchParams.get('action'), 'c2c_file');
        assert.equal(decoded.searchParams.get('receipt_id'), '7');
    }
});

test('HTTP transport buffers binary responses and preserves separate session cookies', async () => {
    const { fetchResponse } = await import('./helpers/http.mjs');
    const image = Buffer.from([0xff, 0xd8, 0xff, 0x00, 0x80]);
    await withServer((request, response) => {
        assert.equal(request.headers.connection, 'close');
        response.writeHead(200, { 'Content-Type': 'image/jpeg', 'Set-Cookie': ['session=one; Path=/', 'cart=two; Path=/'] });
        response.end(image);
    }, async base => {
        const response = await fetchResponse(base);
        assert.equal(response.status, 200);
        assert.equal(response.headers.get('content-type'), 'image/jpeg');
        assert.deepEqual(response.headers.getSetCookie(), ['session=one; Path=/', 'cart=two; Path=/']);
        assert.deepEqual(Buffer.from(await response.arrayBuffer()), image);
    });
});

test('HTTP transport sends multipart image uploads with matching length and boundary', async () => {
    const { fetchResponse } = await import('./helpers/http.mjs');
    await withServer(async (request, response) => {
        const chunks = [];
        for await (const chunk of request) chunks.push(chunk);
        const bytes = Buffer.concat(chunks);
        assert.equal(Number(request.headers['content-length']), bytes.length);
        const boundary = request.headers['content-type'].split('boundary=')[1];
        assert.ok(boundary);
        assert.ok(bytes.toString().startsWith('--' + boundary));
        assert.match(bytes.toString(), /name="nonce"\r\n\r\ntest-nonce/);
        assert.match(bytes.toString(), /filename="receipt.png"/);
        response.setHeader('Content-Type', 'application/json');
        response.end('{"success":true}');
    }, async base => {
        const form = new FormData();
        form.append('nonce', 'test-nonce');
        form.append('receipt', new File(['image'], 'receipt.png', { type: 'image/png' }));
        const response = await fetchResponse(base, { method: 'POST', body: form });
        assert.deepEqual(await response.json(), { success: true });
    });
});

test('HTTP transport returns redirects without following them', async () => {
    const { fetchResponse } = await import('./helpers/http.mjs');
    await withServer((request, response) => {
        assert.equal(request.url, '/');
        response.writeHead(302, { Location: '/redirected' });
        response.end();
    }, async base => {
        const response = await fetchResponse(base);
        assert.equal(response.status, 302);
        assert.equal(response.headers.get('location'), '/redirected');
    });
});
