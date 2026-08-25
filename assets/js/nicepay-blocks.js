( function () {
    'use strict';

    if ( ! window.wc || ! window.wc.wcBlocksRegistry || ! window.wc.wcSettings || ! window.wp || ! window.wp.element ) {
        return;
    }

    var settings = window.wc.wcSettings.getSetting( 'nicepay_data', {} );
    var createElement = window.wp.element.createElement;
    var title = String( settings.title || 'NicePay' );
    var description = String( settings.description || '' );

    var Label = function ( props ) {
        return createElement(
            'span',
            { className: 'nicepay-blocks-label' },
            props?.components?.PaymentMethodLabel
                ? createElement( props.components.PaymentMethodLabel, { text: title } )
                : title
        );
    };

    var Content = function () {
        var children = [ createElement( 'span', { key: 'description' }, description ) ];
        if ( settings.is_test_mode ) {
            children.push(
                createElement(
                    'strong',
                    { key: 'test-mode', className: 'nicepay-blocks-test-mode' },
                    ' ' + String( settings.test_mode_label || '' )
                )
            );
        }
        return createElement( 'div', { className: 'nicepay-blocks-content' }, children );
    };

    window.wc.wcBlocksRegistry.registerPaymentMethod( {
        name: 'nicepay',
        label: createElement( Label, null ),
        ariaLabel: title,
        content: createElement( Content, null ),
        edit: createElement( Content, null ),
        canMakePayment: function () { return settings.is_available === true; },
        placeOrderButtonLabel: String( settings.place_order_label || 'Continue to NicePay' ),
        supports: {
            features: Array.isArray( settings.supports ) ? settings.supports : [ 'products' ],
            showSavedCards: false,
            showSaveOption: false
        }
    } );
}() );
