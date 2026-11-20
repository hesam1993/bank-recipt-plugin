/* global window, document, navigator, XMLHttpRequest, FormData, URL, c2cReceiptsConfig */
(function () {
    'use strict';
    const config = c2cReceiptsConfig;
    const labels = config.labels;
    const contexts = new Map();
    let bootPromise;

    async function parse(response) {
        let body;
        try { body = await response.json(); } catch (_) { throw new Error(labels.error); }
        if (!response.ok || !body.success) throw new Error(body.data?.message || labels.error);
        return body.data;
    }

    function boot() {
        if (!bootPromise) {
            bootPromise = window.fetch(config.bootUrl, {
                method: 'POST', credentials: 'same-origin', cache: 'no-store',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: ''
            }).then(parse).catch(function (error) { bootPromise = null; throw error; });
        }
        return bootPromise;
    }

    async function payload(values, scope) {
        const data = new FormData();
        data.append('nonce', (await boot()).nonce);
        Object.entries({ ...values, order_id: scope.orderId || 0, ...scope.access }).forEach(function ([key, value]) {
            data.append(key, value);
        });
        return data;
    }

    async function request(url, values, scope) {
        return parse(await window.fetch(url, {
            method: 'POST', credentials: 'same-origin', cache: 'no-store',
            body: await payload(values, scope)
        }));
    }

    function storageKey(scope) { return 'c2c-receipt-v1-' + (scope.orderId || 'checkout'); }
    function remember(ctx) {
        try {
            if (ctx.token) {
                window.sessionStorage.setItem(storageKey(ctx.scope), JSON.stringify({
                    token: ctx.token, expires: ctx.expires, account: ctx.account,
                    reference: ctx.reference, amount: ctx.amount
                }));
            } else window.sessionStorage.removeItem(storageKey(ctx.scope));
        } catch (_) { /* Checkout still works when browser storage is disabled. */ }
    }
    function publish(ctx, changes) {
        Object.assign(ctx, changes);
        remember(ctx);
        ctx.listeners.forEach(function (listener) { listener(ctx); });
    }
    function context(scope) {
        const key = storageKey(scope);
        if (contexts.has(key)) return contexts.get(key);
        const ctx = {
            scope, token: '', expires: 0, account: '', reference: '', amount: '',
            busy: false, loading: true, message: '', progress: 0, preview: '', listeners: new Set()
        };
        contexts.set(key, ctx);
        let saved;
        try { saved = JSON.parse(window.sessionStorage.getItem(key)); } catch (_) { /* Empty storage. */ }
        if (saved && /^[a-f0-9]{64}$/.test(saved.token) && saved.expires > Date.now() / 1000) {
            Object.assign(ctx, saved);
            request(config.statusUrl, { token: saved.token }, scope).then(function (status) {
                publish(ctx, { loading: false, account: status.account.id, amount: status.amount, message: labels.ready });
            }).catch(function (error) {
                publish(ctx, { token: '', loading: false, message: error.message });
            });
        } else { ctx.loading = false; remember(ctx); }
        return ctx;
    }

    function validFile(file) {
        return Boolean(file && file.size > 0 && file.size <= config.maxBytes &&
            ['image/jpeg', 'image/png', 'image/webp'].includes(file.type) &&
            /\.(jpe?g|png|webp)$/i.test(file.name));
    }

    async function upload(ctx, file) {
        if (ctx.busy) return;
        if (!validFile(file)) { publish(ctx, { token: '', message: labels.fileError }); return; }
        const previousPreview = ctx.preview;
        const preview = URL.createObjectURL(file);
        publish(ctx, { busy: true, token: '', message: labels.uploading, progress: 0, preview });
        if (previousPreview) URL.revokeObjectURL(previousPreview);
        try {
            const data = await payload({ receipt: file, account: ctx.account, reference: ctx.reference }, ctx.scope);
            const result = await new Promise(function (resolve, reject) {
                const xhr = new XMLHttpRequest();
                xhr.open('POST', config.uploadUrl);
                xhr.withCredentials = true;
                xhr.timeout = 120000;
                xhr.upload.onprogress = function (event) {
                    if (event.lengthComputable) publish(ctx, { progress: Math.round(event.loaded * 100 / event.total) });
                };
                xhr.onload = function () {
                    let body;
                    try { body = JSON.parse(xhr.responseText); } catch (_) { reject(new Error(labels.error)); return; }
                    if (xhr.status >= 200 && xhr.status < 300 && body.success) resolve(body.data);
                    else reject(new Error(body.data?.message || labels.error));
                };
                xhr.onerror = xhr.ontimeout = function () { reject(new Error(labels.error)); };
                xhr.send(data);
            });
            publish(ctx, { token: result.token, expires: result.expires, amount: result.amount, busy: false, message: labels.ready, progress: 100 });
        } catch (error) { publish(ctx, { busy: false, message: error.message }); }
    }

    function element(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text) node.textContent = text;
        return node;
    }

    /** Used by Checkout Blocks; all account content is inserted as text. */
    function build(accounts) {
        const form = element('div', 'c2c-form');
        const accountLabel = element('label', '', labels.account);
        const select = element('select', 'c2c-account');
        accounts.forEach(function (account) {
            const option = element('option', '', [account.bank, account.holder].filter(Boolean).join(' — '));
            option.value = account.id; option.dataset.account = JSON.stringify(account); select.appendChild(option);
        });
        accountLabel.appendChild(select); form.appendChild(accountLabel);
        form.appendChild(element('div', 'c2c-details'));
        const refLabel = element('label', '', labels.reference);
        const ref = element('input', 'c2c-reference'); ref.type = 'text'; ref.maxLength = 120; ref.autocomplete = 'off';
        refLabel.appendChild(ref); form.appendChild(refLabel);
        const fileLabel = element('label', '', labels.receipt);
        const file = element('input', 'c2c-file'); file.type = 'file'; file.accept = 'image/jpeg,image/png,image/webp';
        fileLabel.appendChild(file); form.appendChild(fileLabel);
        form.appendChild(element('p', 'c2c-help'));
        const progress = element('progress', 'c2c-progress'); progress.max = 100; progress.hidden = true; form.appendChild(progress);
        const image = element('img', 'c2c-preview'); image.alt = labels.receipt; image.hidden = true; form.appendChild(image);
        const status = element('p', 'c2c-status'); status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite'); form.appendChild(status);
        const token = element('input', 'c2c-token'); token.type = 'hidden'; token.name = 'c2c_token'; form.appendChild(token);
        return form;
    }

    function mount(form, options = {}) {
        const scope = { orderId: Number(form.dataset.orderId || options.orderId || 0), access: options.access || JSON.parse(form.dataset.access || '{}') };
        const ctx = context(scope);
        const select = form.querySelector('.c2c-account');
        const reference = form.querySelector('.c2c-reference');
        const file = form.querySelector('.c2c-file');
        const status = form.querySelector('.c2c-status');
        const token = form.querySelector('.c2c-token');
        const preview = form.querySelector('.c2c-preview');
        const progress = form.querySelector('.c2c-progress');
        const replacement = form.querySelector('.c2c-replace');
        form.querySelector('.c2c-help').textContent = labels.help.replace('%s', String(Math.round(config.maxBytes / 1048576 * 10) / 10));
        if (!ctx.account) ctx.account = select.value;
        let renderedAccount;
        function render(state) {
            token.value = state.token;
            status.textContent = state.message + (state.token && state.amount ? ' ' + state.amount : '');
            file.disabled = state.busy || state.loading;
            select.disabled = state.busy || state.loading || scope.orderId > 0;
            reference.disabled = state.busy || state.loading || Boolean(state.token);
            reference.value = state.reference;
            if (Array.from(select.options).some(function (option) { return option.value === state.account; })) select.value = state.account;
            if (renderedAccount !== select.value) {
                renderedAccount = select.value;
                const account = JSON.parse(select.selectedOptions[0]?.dataset.account || '{}');
                const details = form.querySelector('.c2c-details'); details.replaceChildren();
                ['holder', 'card', 'number', 'iban'].forEach(function (key) {
                    if (!account[key]) return;
                    const line = element('p');
                    const value = element('bdi', '', account[key]);
                    if (key !== 'holder') value.dir = 'ltr';
                    line.appendChild(value); details.appendChild(line);
                });
                const copy = element('button', 'c2c-copy', labels.copy); copy.type = 'button';
                copy.addEventListener('click', async function () {
                    try { await navigator.clipboard.writeText(account.card); copy.textContent = labels.copied; }
                    catch (_) { status.textContent = account.card; }
                });
                details.appendChild(copy);
            }
            preview.hidden = !state.preview;
            if (state.preview) preview.src = state.preview;
            progress.hidden = !state.busy; progress.value = state.progress;
            if (replacement) replacement.disabled = !state.token || state.busy || state.loading;
            form.setAttribute('aria-busy', String(state.busy || state.loading));
            if (options.onState) options.onState(state);
        }
        ctx.listeners.add(render); render(ctx);
        select.addEventListener('change', function () {
            publish(ctx, { account: select.value, token: '', message: labels.required });
        });
        reference.addEventListener('input', function () { ctx.reference = reference.value; });
        file.addEventListener('change', function () { if (file.files[0]) upload(ctx, file.files[0]); });
        if (replacement) replacement.addEventListener('click', async function () {
            if (!ctx.token || ctx.busy) return;
            publish(ctx, { busy: true });
            try {
                const result = await request(config.replaceUrl, { token: ctx.token }, scope);
                publish(ctx, { token: '', message: result.message, busy: false });
                window.location.reload();
            } catch (error) { publish(ctx, { busy: false, message: error.message }); }
        });
        return { state: ctx, destroy: function () { ctx.listeners.delete(render); } };
    }

    function clear(scope = {}) {
        try { window.sessionStorage.removeItem(storageKey(scope)); } catch (_) { /* No storage. */ }
        const ctx = contexts.get(storageKey(scope));
        if (ctx?.preview) URL.revokeObjectURL(ctx.preview);
        contexts.delete(storageKey(scope));
    }

    window.C2CReceipts = { mount, build, clear, labels, validFile };
}());
