/**
 * "Delivery time" under Luma's delivery options: shown when the merchant
 * offers delivery times and the customer picked a Quickshipper courier that
 * takes a time (the server marks such methods with the extension attribute
 * shubo_qshpr_takes_time); otherwise hidden and empty.
 */
define([
    'ko',
    'uiComponent',
    'Magento_Checkout/js/model/quote',
    'Shubo_Qshpr/js/model/delivery-time'
], function (ko, Component, quote, deliveryTime) {
    'use strict';

    const settings = window.checkoutConfig.shuboQshprDeliveryTime || {carrier: '', slots: []};

    return Component.extend({
        defaults: {
            template: 'Shubo_Qshpr/delivery-time'
        },

        slots: settings.slots,
        value: deliveryTime.value,

        /**
         * @return {Object} Chainable
         */
        initialize: function () {
            this._super();

            this.isVisible = ko.pureComputed(function () {
                const method = quote.shippingMethod();

                return settings.slots.length > 0
                    && !!method
                    && method['carrier_code'] === settings.carrier
                    && !!method['extension_attributes']
                    && method['extension_attributes']['shubo_qshpr_takes_time'] === true;
            });

            this.isVisible.subscribe(function (visible) {
                if (!visible) {
                    deliveryTime.value('');
                }
            });

            return this;
        }
    });
});
