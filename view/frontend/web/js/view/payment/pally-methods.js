define([
    'uiComponent',
    'Magento_Checkout/js/model/payment/renderer-list'
], function (Component, rendererList) {
    rendererList.push({
        type: 'pally',
        component: 'Pally_Payment/js/view/payment/pally'
    });

    return Component.extend({});
});
