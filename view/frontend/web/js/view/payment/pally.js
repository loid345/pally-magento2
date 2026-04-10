define([
    'Magento_Checkout/js/view/payment/default',
    'Magento_Checkout/js/model/full-screen-loader',
    'Magento_Checkout/js/model/error-processor',
    'Magento_Checkout/js/checkout-data',
    'Magento_Checkout/js/model/quote',
    'Magento_Checkout/js/model/payment/additional-validators',
    'mage/translate'
], function (Component, fullScreenLoader, errorProcessor, checkoutData, quote, additionalValidators, $t) {
    'use strict';

    var PREFIX = '[Pally Debug]';

    function isDebug() {
        var cfg = window.checkoutConfig && window.checkoutConfig.payment && window.checkoutConfig.payment.pally;
        return cfg && cfg.isDebugMode;
    }

    function log() {
        if (!isDebug()) {
            return;
        }
        var args = Array.prototype.slice.call(arguments);
        args.unshift(PREFIX);
        console.log.apply(console, args);
    }

    function warn() {
        if (!isDebug()) {
            return;
        }
        var args = Array.prototype.slice.call(arguments);
        args.unshift(PREFIX);
        console.warn.apply(console, args);
    }

    return Component.extend({
        defaults: {
            template: 'Pally_Payment/payment/pally',
            redirectAfterPlaceOrder: false
        },

        initialize: function () {
            this._super();

            if (isDebug()) {
                var config = window.checkoutConfig.payment.pally;
                log('=== Component initialized ===');
                log('checkoutConfig.payment.pally:', JSON.stringify(config, null, 2));
                log('isPlaceOrderActionAllowed():', this.isPlaceOrderActionAllowed());
                log('isChecked():', this.isChecked());
                log('getCode():', this.getCode());
                log('quote.paymentMethod():', JSON.stringify(quote.paymentMethod()));
                log('quote.billingAddress():', JSON.stringify(quote.billingAddress()));
                log('quote.shippingAddress():', JSON.stringify(quote.shippingAddress()));
                log('isDisplayBillingOnPaymentMethod:', window.checkoutConfig.isDisplayBillingOnPaymentMethod);
                log('redirectAfterPlaceOrder:', this.redirectAfterPlaceOrder);

                // Track isPlaceOrderActionAllowed changes
                var self = this;
                this.isPlaceOrderActionAllowed.subscribe(function (newValue) {
                    log('isPlaceOrderActionAllowed changed to:', newValue);
                    log('  (call stack):', new Error().stack);
                });
            }

            return this;
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

        selectPaymentMethod: function () {
            log('=== selectPaymentMethod() called ===');
            log('  getCode():', this.getCode());
            log('  isChecked() BEFORE:', this.isChecked());
            log('  isPlaceOrderActionAllowed() BEFORE:', this.isPlaceOrderActionAllowed());

            var result = this._super();

            log('  isChecked() AFTER:', this.isChecked());
            log('  isPlaceOrderActionAllowed() AFTER:', this.isPlaceOrderActionAllowed());
            log('  quote.paymentMethod() AFTER:', JSON.stringify(quote.paymentMethod()));
            log('  quote.billingAddress() AFTER:', JSON.stringify(quote.billingAddress()));

            return result;
        },

        placeOrder: function (data, event) {
            log('=== placeOrder() called ===');
            log('  isPlaceOrderActionAllowed():', this.isPlaceOrderActionAllowed());
            log('  validate():', this.validate());
            log('  additionalValidators.validate():', additionalValidators.validate());
            log('  isChecked():', this.isChecked());
            log('  getCode():', this.getCode());
            log('  quote.paymentMethod():', JSON.stringify(quote.paymentMethod()));
            log('  quote.billingAddress():', JSON.stringify(quote.billingAddress()));
            log('  getData():', JSON.stringify(this.getData()));

            var canPlace = this.validate() &&
                           additionalValidators.validate() &&
                           this.isPlaceOrderActionAllowed() === true;

            if (!canPlace) {
                warn('=== placeOrder BLOCKED ===');
                warn('  validate():', this.validate());
                warn('  additionalValidators.validate():', additionalValidators.validate());
                warn('  isPlaceOrderActionAllowed():', this.isPlaceOrderActionAllowed());
            } else {
                log('=== placeOrder PROCEEDING, calling parent ===');
            }

            return this._super(data, event);
        },

        afterPlaceOrder: function () {
            log('=== afterPlaceOrder() called ===');
            var config = window.checkoutConfig.payment.pally;
            log('  config:', JSON.stringify(config, null, 2));
            log('  redirectUrl:', config ? config.redirectUrl : 'N/A');

            if (config && config.redirectUrl) {
                log('  Redirecting to:', config.redirectUrl);
                fullScreenLoader.startLoader();
                window.location.replace(config.redirectUrl);
            } else {
                warn('  redirectUrl is MISSING, showing error');
                fullScreenLoader.stopLoader();
                errorProcessor.process({
                    status: 500,
                    responseText: JSON.stringify({message: $t('Payment redirect URL is not available. Please try again.')})
                });
            }
        }
    });
});
