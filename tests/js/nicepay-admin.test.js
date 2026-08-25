'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const { JSDOM } = require('jsdom');
const jqueryFactory = require('jquery');

const source = fs.readFileSync(
    path.resolve(__dirname, '../../assets/js/nicepay-admin.js'),
    'utf8'
);

function tick() {
    return new Promise(function(resolve) {
        setImmediate(resolve);
    });
}

function createEnvironment(postResponse, i18nOverrides) {
    const dom = new JSDOM(
        '<!doctype html><html><body><table><tbody><tr><td><span class="nicepay-status nicepay-status-paid">PAID</span></td><td><button type="button" class="nicepay-cancel-btn" data-tid="TID-1" data-id="1" data-nonce="nonce">Refund</button></td></tr></tbody></table></body></html>',
        { runScripts: 'outside-only', url: 'https://merchant.example/wp-admin/' }
    );
    const { window } = dom;
    const $ = jqueryFactory(window);
    const posts = [];

    window.jQuery = $;
    window.$ = $;
    window.nicepayAdmin = {
        ajaxUrl: 'https://merchant.example/wp-admin/admin-ajax.php',
        i18n: Object.assign({
            confirm: 'Confirm',
            cancel: 'Cancel',
            cancelTitle: 'Refund transaction',
            cancelMessage: 'Cannot undo',
            cancelReasonLabel: 'Reason',
            cancelReasonPlaceholder: 'Why?',
            cancelConfirm: 'Refund',
            cancelReasonRequired: 'Reason is required.',
            cancelFailed: 'Refund failed.',
            requestFailed: 'Request failed.',
            statusRefunded: 'REFUNDED'
        }, i18nOverrides || {})
    };
    $.post = function(url, data, callback) {
        posts.push({ url, data });
        callback(postResponse);
        return {
            fail: function() {
                return this;
            }
        };
    };

    window.eval(source);
    return { dom, window, $, posts };
}

test('refund validation is announced and a failed request keeps the entered reason in the modal', async () => {
    const environment = createEnvironment({ success: false, data: { message: 'Provider rejected this refund.' } });
    const { window, $, posts } = environment;
    const button = window.document.querySelector('.nicepay-cancel-btn');

    button.focus();
    await tick();
    $(button).trigger('click');
    await tick();

    const input = window.document.getElementById('nicepay-modal-input');
    assert.ok(input);
    assert.equal(window.document.activeElement, input);
    assert.equal(window.document.querySelector('.nicepay-modal').getAttribute('aria-busy'), 'false');

    $('#nicepay-modal-confirm').trigger('click');
    assert.equal(posts.length, 0);
    assert.equal(input.getAttribute('aria-invalid'), 'true');
    assert.equal(window.document.getElementById('nicepay-modal-error').textContent, 'Reason is required.');

    $(input).val('Duplicate customer charge');
    $('#nicepay-modal-confirm').trigger('click');

    assert.equal(posts.length, 1);
    assert.equal(posts[0].data.reason, 'Duplicate customer charge');
    assert.equal(window.document.getElementById('nicepay-modal-input').value, 'Duplicate customer charge');
    assert.equal(window.document.getElementById('nicepay-modal-error').textContent, 'Provider rejected this refund.');
    assert.equal(window.document.getElementById('nicepay-modal-confirm').disabled, false);
    assert.equal(window.document.activeElement, input);
    assert.equal(window.document.querySelectorAll('.nicepay-toast').length, 1);

    environment.dom.window.close();
});

test('modal traps keyboard focus and toast notifications can coexist', async () => {
    const environment = createEnvironment({ success: true, data: { message: 'Refunded.' } });
    const { window, $ } = environment;
    await tick();
    const refundButton = window.document.querySelector('.nicepay-cancel-btn');
    refundButton.focus();
    $(refundButton).trigger('click');
    await tick();

    const confirm = window.document.getElementById('nicepay-modal-confirm');
    confirm.focus();
    confirm.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true }));
    assert.equal(window.document.activeElement, window.document.getElementById('nicepay-modal-input'));

    window.NicePayToast.show('First', 'info', 10000);
    window.NicePayToast.show('Second', 'success', 10000);
    assert.equal(window.document.querySelectorAll('.nicepay-toast').length, 2);

    $('#nicepay-modal-cancel').trigger('click');
    assert.equal(window.document.getElementById('nicepay-modal'), null);
    assert.equal(window.document.activeElement, refundButton);

    environment.dom.window.close();
});

test('localized modal and toast text cannot create executable markup', async () => {
    const attack = '<img src=x onerror="window.__nicepayXss=true">';
    const environment = createEnvironment(
        { success: false, data: { message: attack } },
        { cancelTitle: attack, cancelMessage: attack, cancelReasonPlaceholder: attack }
    );
    const { window, $ } = environment;

    $('.nicepay-cancel-btn').trigger('click');
    await tick();

    assert.equal(window.document.querySelector('.nicepay-modal-title').textContent, attack);
    assert.equal(window.document.getElementById('nicepay-modal-input').placeholder, attack);
    assert.equal(window.document.querySelector('#nicepay-modal img'), null);

    window.NicePayToast.show(attack, 'success', 10000);
    assert.equal(window.document.querySelector('.nicepay-toast').textContent.includes(attack), true);
    assert.equal(window.document.querySelector('.nicepay-toast img'), null);
    assert.equal(window.__nicepayXss, undefined);

    environment.dom.window.close();
});
