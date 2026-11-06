/**
 * "Delivery time" under Luma's delivery options: shown when the merchant
 * offers delivery times and the customer picked a Quickshipper courier that
 * takes a time (the server marks such methods with the extension attribute
 * shubo_qshpr_takes_time); otherwise hidden and empty. While the shop is
 * closed (settings.asap false) the customer must pick a time.
 */
define([
    'ko',
    'mage/translate',
    'uiComponent',
    'Magento_Checkout/js/model/quote',
    'Shubo_Qshpr/js/model/delivery-time'
], function (ko, $t, Component, quote, deliveryTime) {
    'use strict';

    const settings = window.checkoutConfig.shuboQshprDeliveryTime || {carrier: '', asap: true, slots: []};

    return Component.extend({
        defaults: {
            template: 'Shubo_Qshpr/delivery-time'
        },

        slots: settings.slots,
        value: deliveryTime.value,

        // While the shop is closed "as soon as possible" is not offered, so
        // the list has no empty first entry and starts on the first slot.
        asapCaption: settings.asap === false ? undefined : $t('As soon as possible'),

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

            this.isVisible.subscribe(this.choose, this);
            this.choose(this.isVisible());

            return this;
        },

        /**
         * Empty the choice when the list is hidden; pick the first slot when
         * it is shown without "as soon as possible".
         *
         * @param {Boolean} visible
         */
        choose: function (visible) {
            if (!visible) {
                deliveryTime.value('');
            } else if (settings.asap === false && !deliveryTime.value()) {
                deliveryTime.value(settings.slots[0].value);
            }
        }
    });
});
