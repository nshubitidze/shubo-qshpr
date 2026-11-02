/**
 * The delivery time the customer chose at checkout ('' = as soon as possible),
 * shared by the delivery-time list and the shipping step's save.
 */
define(['ko'], function (ko) {
    'use strict';

    return {
        value: ko.observable('')
    };
});
