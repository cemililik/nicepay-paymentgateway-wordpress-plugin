<?php
/**
 * NicePay Payment Method Icons
 *
 * Returns inline SVG icons for each payment method.
 * Uses currentColor for theming compatibility.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Get SVG icon markup for a payment method
 *
 * @param string $method Payment method code
 * @return string SVG HTML markup
 */
function nicepay_get_method_icon( $method ) {
    $icons = array(
        'CARD' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <rect x="2" y="5" width="20" height="14" rx="2"/>
            <line x1="2" y1="10" x2="22" y2="10"/>
            <line x1="6" y1="14" x2="10" y2="14"/>
        </svg>',

        'BANK' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M3 21h18"/>
            <path d="M3 10h18"/>
            <path d="M12 3l9 7H3l9-7z"/>
            <path d="M6 10v11"/>
            <path d="M10 10v11"/>
            <path d="M14 10v11"/>
            <path d="M18 10v11"/>
        </svg>',

        'VBANK' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M3 21h18"/>
            <path d="M3 10h18"/>
            <path d="M12 3l9 7H3l9-7z"/>
            <path d="M6 10v11"/>
            <path d="M18 10v11"/>
            <circle cx="12" cy="16" r="3"/>
            <path d="M12 14.5v1.5l1 1"/>
        </svg>',

        'CELLPHONE' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <rect x="6" y="2" width="12" height="20" rx="2"/>
            <line x1="10" y1="18" x2="14" y2="18"/>
            <line x1="6" y1="6" x2="18" y2="6"/>
        </svg>',

        'SSG_BANK' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <rect x="2" y="6" width="20" height="14" rx="2"/>
            <path d="M2 10h20"/>
            <circle cx="16" cy="15" r="2"/>
            <circle cx="19" cy="15" r="2"/>
        </svg>',

        'GIFT_CULT' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="8" width="18" height="13" rx="1"/>
            <path d="M12 8v13"/>
            <path d="M3 12h18"/>
            <path d="M7.5 8C7.5 8 7 3 12 3s4.5 5 4.5 5"/>
        </svg>',
    );

    $svg = isset( $icons[ $method ] ) ? $icons[ $method ] : '';

    if ( ! $svg ) {
        return '';
    }

    return '<span class="nicepay-method-icon" aria-hidden="true">' . $svg . '</span>';
}
