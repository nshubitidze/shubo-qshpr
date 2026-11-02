/**
 * Courier logos in Luma's list of shipping methods, and the delivery time
 * chosen at checkout travelling with the shipping step.
 */
var config = {
    config: {
        mixins: {
            'Magento_Checkout/js/view/shipping': {
                'Shubo_Qshpr/js/mixin/shipping-courier-logos': true
            },
            'Magento_Checkout/js/model/shipping-save-processor/payload-extender': {
                'Shubo_Qshpr/js/mixin/shipping-payload-delivery-time': true
            }
        }
    }
};
