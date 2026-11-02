/**
 * Sends the chosen delivery time with the shipping step
 * (addressInformation.extension_attributes.shubo_qshpr_delivery_time).
 * The server keeps it only if it is a slot the store offers.
 */
define([
    'mage/utils/wrapper',
    'Shubo_Qshpr/js/model/delivery-time'
], function (wrapper, deliveryTime) {
    'use strict';

    return function (payloadExtender) {
        return wrapper.wrap(payloadExtender, function (original, payload) {
            const result = original(payload);

            result.addressInformation['extension_attributes'] = result.addressInformation['extension_attributes'] || {};
            result.addressInformation['extension_attributes']['shubo_qshpr_delivery_time'] = deliveryTime.value() || '';

            return result;
        });
    };
});
