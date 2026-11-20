/* global document */
(function () {
    'use strict';
    document.addEventListener('click', function (event) {
        const button = event.target.closest('.c2c-decision');
        if (!button) return;
        const panel = button.closest('.c2c-review');
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = panel.dataset.url;
        const values = {
            action: 'c2c_review', order_id: panel.dataset.order,
            receipt_id: panel.dataset.receipt, nonce: panel.dataset.nonce,
            decision: button.dataset.decision,
            confirmed: panel.querySelector('.c2c-confirm').checked ? 'yes' : 'no',
            reason: panel.querySelector('.c2c-reason').value
        };
        Object.entries(values).forEach(function ([name, value]) {
            const input = document.createElement('input');
            input.type = 'hidden'; input.name = name; input.value = value;
            form.appendChild(input);
        });
        panel.querySelectorAll('button').forEach(function (item) { item.disabled = true; });
        document.body.appendChild(form);
        form.submit();
    });
}());
