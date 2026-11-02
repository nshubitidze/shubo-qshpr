/**
 * Luma's shipping step with Quickshipper courier logos: uses the module's
 * shipping method row, which shows the logo when the method carries one.
 */
define([], function () {
    'use strict';

    return function (Shipping) {
        return Shipping.extend({
            defaults: {
                shippingMethodItemTemplate: 'Shubo_Qshpr/shipping-method-item'
            },

            /**
             * A logo that cannot be loaded is removed; the courier's name stays.
             *
             * @param {Object} data
             * @param {Event} event
             * @return {void}
             */
            hideBrokenCourierLogo: function (data, event) {
                event.target.style.display = 'none';
            }
        });
    };
});
