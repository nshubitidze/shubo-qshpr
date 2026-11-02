/**
 * Courier logos in Luma's list of shipping methods.
 */
var config = {
    config: {
        mixins: {
            'Magento_Checkout/js/view/shipping': {
                'Shubo_Qshpr/js/mixin/shipping-courier-logos': true
            }
        }
    }
};
