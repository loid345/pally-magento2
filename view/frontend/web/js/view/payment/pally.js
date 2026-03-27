define([
    'Magento_Checkout/js/view/payment/default',
    'Magento_Checkout/js/model/full-screen-loader',
    'Magento_Checkout/js/model/error-processor',
    'mage/translate'
], function (Component, fullScreenLoader, errorProcessor, $t) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'Pally_Payment/payment/pally',
            redirectAfterPlaceOrder: false
        },

        getCode: function () {
            return 'pally';
        },

        getData: function () {
            return {
                'method': this.getCode(),
                'additional_data': {}
            };
        },

        getTitle: function () {
            var config = window.checkoutConfig.payment.pally;
            return config ? config.title : 'Pally';
        },

        getDescription: function () {
            var config = window.checkoutConfig.payment.pally;
            return config ? config.description : '';
        },

        getInstructions: function () {
            var config = window.checkoutConfig.payment.pally;
            return config ? config.instructions : '';
        },

        afterPlaceOrder: function () {
            var config = window.checkoutConfig.payment.pally;

            if (config && config.redirectUrl) {
                fullScreenLoader.startLoader();
                window.location.replace(config.redirectUrl);
            } else {
                fullScreenLoader.stopLoader();
                errorProcessor.process({
                    status: 500,
                    responseText: JSON.stringify({message: $t('Payment redirect URL is not available. Please try again.')})
                });
            }
        }
    });
});
