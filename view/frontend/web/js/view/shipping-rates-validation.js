/**
 * Registers Quickshipper's rate rules and validator with Luma checkout.
 * The carrier code must match Shubo\Qshpr\Model\Ui\ConfigProvider::CODE.
 */
define([
    'uiComponent',
    'uiRegistry',
    'Magento_Checkout/js/model/shipping-rates-validator',
    'Magento_Checkout/js/model/shipping-rates-validation-rules',
    'Shubo_Qshpr/js/model/shipping-rates-validator',
    'Shubo_Qshpr/js/model/shipping-rates-validation-rules'
], function (Component, registry, ratesValidator, ratesValidationRules, qshprValidator, qshprRules) {
    'use strict';

    const CARRIER_CODE = 'shuboqshpr';

    /**
     * Street line 1 of Luma's shipping address form.
     */
    const STREET_LINE_1 = 'checkout.steps.shipping-step.shippingAddress.shipping-address-fieldset.street.0';

    ratesValidator.registerValidator(CARRIER_CODE, qshprValidator);
    ratesValidationRules.registerRules(CARRIER_CODE, qshprRules);

    // Registering "street" is not enough: checkout binds the street group
    // before its lines exist, so typing a street never asks for rates again
    // (a city change does). Bind line 1 ourselves as soon as it exists; the
    // validator debounces, so a second binding cannot double the requests.
    registry.async(STREET_LINE_1)(function (element) {
        ratesValidator.doElementBinding(element, true);
    });

    return Component;
});
