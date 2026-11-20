/* global window */
(function () {
    'use strict';
    const { createElement: h, useEffect, useRef } = window.wp.element;
    const { decodeEntities } = window.wp.htmlEntities;
    const settings = window.wc.wcSettings.getSetting('c2c_receipts_data', {});
    const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
    const C2CReceipts = window.C2CReceipts;

    function Content(props) {
        const root = useRef(null);
        const widget = useRef(null);
        const onPaymentSetup = props.eventRegistration.onPaymentSetup;
        const { SUCCESS, ERROR } = props.emitResponse.responseTypes;
        const currency = props.billing?.currency;
        const total = props.billing?.cartTotal?.value;
        const code = currency?.code || '';
        const displayedTotal = total === undefined ? '' :
            (Number(total) / Math.pow(10, currency?.minorUnit ?? 2)).toLocaleString(undefined, { maximumFractionDigits: currency?.minorUnit ?? 2 }) + ' (' + code + ')';
        useEffect(function () {
            const form = C2CReceipts.build(settings.accounts || []);
            root.current.appendChild(form);
            widget.current = C2CReceipts.mount(form);
            return function () { widget.current.destroy(); form.remove(); };
        }, []);
        useEffect(function () {
            return onPaymentSetup(function () {
                const state = widget.current?.state;
                if (!state || state.busy || state.loading || !state.token || state.expires < Date.now() / 1000) {
                    return { type: ERROR, message: C2CReceipts.labels.required };
                }
                return { type: SUCCESS, meta: { paymentMethodData: { c2c_token: state.token } } };
            });
        }, [onPaymentSetup, SUCCESS, ERROR]);
        return h('div', { 'data-c2c-blocks': '1' },
            h('p', null, decodeEntities(settings.description || '')),
            displayedTotal ? h('p', null, displayedTotal) : null,
            h('div', { ref: root })
        );
    }

    registerPaymentMethod({
        name: 'c2c_receipts', gatewayId: 'c2c_receipts',
        label: h('span', null, decodeEntities(settings.title || 'Card to card')),
        ariaLabel: decodeEntities(settings.title || 'Card to card'),
        content: h(Content), edit: h('p', null, decodeEntities(settings.description || '')),
        canMakePayment: function () { return Boolean(settings.accounts?.length); },
        supports: { features: settings.supports || ['products'] }
    });
}());
