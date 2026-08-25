'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const { JSDOM } = require('jsdom');
const jqueryFactory = require('jquery');

const source = fs.readFileSync(
    path.resolve(__dirname, '../../assets/js/nicepay-shortcode-admin.js'),
    'utf8'
);

function tick() {
    return new Promise(function(resolve) {
        setImmediate(resolve);
    });
}

function markup() {
    return `<!doctype html><html><body>
        <div class="nicepay-sc-builder" data-edit-id="saved-card">
        <input id="sc-name"><input id="sc-amount"><input id="sc-goods-name"><select id="sc-goods-class"><option value="0">0</option><option value="1">1</option></select>
        <input id="sc-buyer-name"><input id="sc-buyer-email"><input id="sc-buyer-tel"><input id="sc-button-text"><input id="sc-button-class"><input id="sc-button-color" value="#2563eb"><select id="sc-currency"><option value="KRW">KRW</option><option value="USD">USD</option></select><select id="sc-language"><option value="">Default</option><option value="EN">EN</option></select>
        <label class="nicepay-sc-display-mode"><input type="radio" name="sc-display-mode" value="inline" checked></label><label class="nicepay-sc-display-mode"><input type="radio" name="sc-display-mode" value="modal"></label>
        <label class="nicepay-sc-method-chip"><input type="radio" name="sc-pay-method" value="" checked></label><label class="nicepay-sc-method-chip"><input type="radio" name="sc-pay-method" value="CARD"></label>
        <button id="sc-copy-btn"><span>Copy</span></button><button id="sc-save-btn">Save Shortcode</button>
        <span id="sc-output-code"></span><span id="sc-pv-product"></span><span id="sc-pv-amount"></span><div id="sc-pv-methods"><div id="sc-pv-method-options"></div></div><div id="sc-pv-fields"></div><button id="sc-preview-btn"></button><div id="sc-validation"><span id="sc-validation-msg"></span></div>
        </div>
    </body></html>`;
}

test('shortcode generator is a standalone asset and restores saved configuration safely', async () => {
    const php = fs.readFileSync(path.resolve(__dirname, '../../admin/class-nicepay-admin.php'), 'utf8');
    assert.equal(php.includes('<script>\n        jQuery(function($)'), false);

    const dom = new JSDOM(markup(), { runScripts: 'outside-only', url: 'https://merchant.example/wp-admin/' });
    const { window } = dom;
    const $ = jqueryFactory(window);
    window.jQuery = $;
    window.$ = $;
    window.nicepayAdmin = { ajaxUrl: '/ajax', shortcodeNonce: 'nonce' };
    window.nicepayShortcodeAdmin = {
        editId: 'saved-card',
        editData: { name: 'Card order', amount: '1200', goods_name: 'Membership', goods_class: '1', pay_method: 'CARD', display_mode: 'modal', button_text: 'Pay membership', currency: 'KRW' },
        enabledMethods: [{ icon: '<svg onload="window.__nicepayXss=true"></svg>', label: 'Card' }],
        redirectUrl: '/shortcodes',
        i18n: { payNow: 'Pay Now', productName: 'Product Name', amountRequired: 'Amount is required', productNameRequired: 'Product name is required', referenceShortcode: 'Save first', copy: 'Copy', copied: 'Copied!', saving: 'Saving...', saveShortcode: 'Save Shortcode', updateShortcode: 'Update Shortcode' }
    };
    window.NicePayToast = { show: function() {} };
    $.post = function() {
        return { fail: function() { return this; } };
    };

    window.eval(source);
    await tick();

    assert.equal(window.document.getElementById('sc-name').value, 'Card order');
    assert.equal(window.document.getElementById('sc-output-code').textContent, '[nicepay_payment id="saved-card"]');
    assert.equal(window.document.getElementById('sc-pv-product').textContent, 'Membership');
    assert.equal(window.document.getElementById('sc-pv-methods').style.display, 'none', 'single-method preview stays hidden');
    assert.equal(window.document.getElementById('sc-preview-btn').style.getPropertyValue('--nicepay-button-text'), '#ffffff');
    assert.equal(window.document.querySelector('input[name="sc-pay-method"][value="CARD"]').checked, true);
    assert.equal(window.document.querySelector('input[name="sc-display-mode"][value="modal"]').checked, true);
    assert.equal(window.document.getElementById('sc-pv-method-options').textContent, 'Card');
    assert.equal(window.document.querySelector('#sc-pv-method-options svg'), null);
    assert.equal(window.__nicepayXss, undefined);

    $('#sc-name').val('');
    $('#sc-save-btn').trigger('click');
    assert.equal(window.document.getElementById('sc-name').getAttribute('aria-invalid'), 'true');
    assert.equal(window.document.activeElement, window.document.getElementById('sc-name'));

    $('#sc-button-color').val('#ffffff').trigger('input');
    assert.equal(window.document.getElementById('sc-preview-btn').style.getPropertyValue('--nicepay-button-text'), '#111827');

    dom.window.close();
});
