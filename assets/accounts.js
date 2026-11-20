/* global document, window */
(function () {
    'use strict';
    function notify(root) {
        root.querySelector('.c2c-accounts-empty').dispatchEvent(new Event('change', { bubbles: true }));
    }
    document.querySelectorAll('.c2c-accounts').forEach(function (root) {
        const rows = root.querySelector('.c2c-account-rows');
        const add = root.querySelector('.c2c-account-add');
        function refresh() {
            add.hidden = false;
            add.disabled = rows.children.length >= 20;
            rows.querySelectorAll('.c2c-account-remove').forEach(function (button) { button.hidden = false; });
        }
        add.addEventListener('click', function () {
            if (rows.children.length >= 20) return;
            const index = String(Number(root.dataset.nextIndex));
            root.dataset.nextIndex = String(Number(index) + 1);
            const fragment = root.querySelector('.c2c-account-template').content.cloneNode(true);
            fragment.querySelectorAll('[name]').forEach(function (input) { input.name = input.name.replace('__index__', index); });
            rows.appendChild(fragment);
            refresh(); notify(root);
            rows.lastElementChild.querySelector('input[type="text"]').focus();
        });
        rows.addEventListener('click', function (event) {
            const button = event.target.closest('.c2c-account-remove');
            if (!button) return;
            button.closest('.c2c-account-row').remove();
            refresh(); notify(root);
        });
        // WooCommerce binds dirty-form handlers to the original fields; notify that stable field for new rows.
        rows.addEventListener('input', function () { notify(root); });
        refresh();
    });
}());
