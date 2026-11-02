/**
 * Address fields whose changes make checkout ask for shipping prices again.
 * Quickshipper prices by the exact address, so the street and city count, not
 * only the country and postcode.
 */
define([], function () {
    'use strict';

    return {
        /**
         * @return {Object}
         */
        getRules: function () {
            return {
                'country_id': {required: true},
                'city': {required: true},
                'street': {required: true}
            };
        }
    };
});
