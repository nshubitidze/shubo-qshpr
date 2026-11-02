/**
 * Tells checkout whether the address is complete enough to ask Quickshipper
 * for prices: country, city and the first street line. Only a trigger — the
 * server decides whether the address can be priced.
 */
define([], function () {
    'use strict';

    /**
     * @param {*} value
     * @return {Boolean}
     */
    const isFilled = function (value) {
        return typeof value === 'string' && value.trim() !== '';
    };

    return {
        /**
         * @param {Object} address Flat checkout address; street is {0: line 1, 1: line 2}.
         * @return {Boolean}
         */
        validate: function (address) {
            return isFilled(address['country_id'])
                && isFilled(address.city)
                && !!address.street
                && isFilled(address.street[0]);
        }
    };
});
