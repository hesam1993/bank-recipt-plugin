/* global window, document, jQuery */
(function ($) {
    'use strict';
    const mounted = new Map();
    function init() {
        mounted.forEach(function (widget, form) {
            if (!form.isConnected) { widget.destroy(); mounted.delete(form); }
        });
        if (document.querySelector('[data-c2c-clear]')) window.C2CReceipts.clear();
        document.querySelectorAll('.c2c-form').forEach(function (form) {
            if (!mounted.has(form) && !form.closest('[data-c2c-blocks]')) mounted.set(form, window.C2CReceipts.mount(form));
        });
    }
    $(init);
    $(document.body).on('updated_checkout', init);
    $('form.checkout').on('checkout_place_order_c2c_receipts', function () {
        const form = document.querySelector('#payment .c2c-form');
        const state = form && mounted.get(form)?.state;
        if (!state || state.busy || state.loading || !state.token || state.expires < Date.now() / 1000) {
            if (form) {
                form.querySelector('.c2c-status').textContent = window.C2CReceipts.labels.required;
                form.querySelector('.c2c-file').focus();
            }
            return false;
        }
        return true;
    });
}(jQuery));
