'use strict';

const globals = require('globals');

module.exports = [
    {
        files: ['assets/js/**/*.js'],
        languageOptions: {
            ecmaVersion: 2020,
            sourceType: 'script',
            globals: {
                ...globals.browser,
                ajaxurl: 'readonly',
                jQuery: 'readonly',
                nicepayAdmin: 'readonly',
                nicepayParams: 'readonly',
                nicepayStart: 'readonly',
                wc: 'readonly',
                wp: 'readonly'
            }
        },
        rules: {
            'no-undef': 'error',
            'no-unused-vars': ['error', { argsIgnorePattern: '^_' }],
            'no-empty': ['error', { allowEmptyCatch: true }]
        }
    },
    {
        files: ['.github/scripts/**/*.js', 'tests/js/**/*.js', 'eslint.config.js'],
        languageOptions: {
            ecmaVersion: 2022,
            sourceType: 'commonjs',
            globals: globals.node
        },
        rules: {
            'no-undef': 'error',
            'no-unused-vars': ['error', { argsIgnorePattern: '^_' }]
        }
    }
];
