define([
    'uiComponent',
    'Magento_Checkout/js/model/payment/renderer-list'
], function (Component, rendererList) {
    'use strict';

    rendererList.push({
        type: 'pally',
        component: 'Pally_Payment/js/view/payment/pally'
    });

    return Component.extend({});
});
