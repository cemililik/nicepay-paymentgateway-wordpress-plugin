'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const { JSDOM } = require('jsdom');
const jqueryFactory = require('jquery');

const source = fs.readFileSync(
    path.resolve(__dirname, '../../assets/js/nicepay.js'),
    'utf8'
);

function formMarkup(id, configId) {
    return `
        <div id="${id}-wrapper" class="nicepay-payment-wrapper">
            <input id="${id}-visible-name" class="nicepay-field-input" data-field="BuyerName" data-max-bytes="30" value="Buyer ${id}">
            <input id="${id}-visible-email" type="email" class="nicepay-field-input" data-field="BuyerEmail" data-max-bytes="60" value="${id}@example.com">
            <input id="${id}-visible-tel" type="tel" class="nicepay-field-input" data-field="BuyerTel" data-max-bytes="20" value="01012345678">
            <div class="nicepay-methods">
                <label class="nicepay-method-option is-selected">
                    <input type="radio" name="nicepay_method_${id}" value="CARD" checked>
                </label>
            </div>
            <form id="${id}" class="nicepay-standalone-form" data-nicepay-config-id="${configId}" data-nicepay-init-nonce="nonce-${id}">
				<input name="EdiDate"><input name="Moid"><input name="SignData"><input name="ReqReserved">
                <input name="Amt"><input name="CurrencyCode"><input name="GoodsName">
                <input name="PayMethod" value="CARD"><input name="MID"><input name="ReturnURL">
                <input name="BuyerName"><input name="BuyerEmail"><input name="BuyerTel">
                <input name="CharSet"><input data-nicepay-goods-class>
                <div class="nicepay-submit-wrapper">
					<button type="submit" data-nicepay-start="${id}">Pay ${id}</button>
                </div>
            </form>
        </div>`;
}

function createEnvironment(returnUrl) {
    const dom = new JSDOM(
        `<!doctype html><html><body>${formMarkup('form-a', 'config-a')}${formMarkup('form-b', 'config-b')}</body></html>`,
        { runScripts: 'outside-only', url: 'https://merchant.example/pay' }
    );
    const { window } = dom;
    const requests = [];
    const starts = [];

    window.jQuery = jqueryFactory(window);
    window.$ = window.jQuery;
    window.nicepayParams = {
        ajaxUrl: 'https://merchant.example/wp-admin/admin-ajax.php',
        i18n: {}
    };
    window.setTimeout = function() { return 1; };
    window.nicepayStart = function() {
        starts.push(window.document.querySelector('.nicepay-standalone-form[name="payForm"]').id);
    };
    window.XMLHttpRequest = class FakeXMLHttpRequest {
        open(method, url) {
            this.method = method;
            this.url = url;
        }

        setRequestHeader() {}

        send(body) {
            const params = new URLSearchParams(body);
            const configId = params.get('config_id');
            requests.push({ body, configId });
            this.status = 200;
            this.responseText = JSON.stringify({
                success: true,
                data: {
                    edi_date: '20260820120000',
                    moid: `SP_${configId}`,
                    sign_data: 'a'.repeat(64),
					req_reserved: `binding-${configId}`,
                    amount: '1004',
                    currency: 'KRW',
                    goods_name: `Goods ${configId}`,
                    goods_class: '1',
                    pay_method: 'CARD',
                    mid: 'nicepay00m',
                    return_url: returnUrl || 'https://merchant.example/nicepay-return/',
                    charset: 'utf-8'
                }
            });
            this.onload();
        }
    };

    window.eval(source);
    return { dom, window, requests, starts };
}

test('two shortcode instances keep commercial configuration and callbacks scoped', () => {
    const environment = createEnvironment();
    const { window, requests, starts } = environment;
    const document = window.document;

    document.querySelector('[data-nicepay-start="form-a"]').click();

    assert.equal(requests.length, 1);
    assert.equal(requests[0].configId, 'config-a');
    assert.equal(document.querySelector('#form-a [name="Moid"]').value, 'SP_config-a');
	assert.equal(document.querySelector('#form-a [name="ReqReserved"]').value, 'binding-config-a');
    assert.equal(document.querySelector('#form-b [name="Moid"]').value, '');
    assert.equal(document.querySelectorAll('.nicepay-standalone-form[name="payForm"]').length, 1);
    assert.deepEqual(starts, ['form-a']);

    assert.equal(document.querySelector('[data-nicepay-start="form-b"]').disabled, true);
	document.getElementById('form-b').dispatchEvent(
		new window.SubmitEvent('submit', { bubbles: true, cancelable: true })
    );
    assert.equal(requests.length, 1, 'a second instance cannot replace an active PG session');
    assert.equal(document.querySelector('#form-b-wrapper [role="alert"]').textContent, '!Another payment is already in progress.');

    window.nicepayClose();
    document.querySelector('[data-nicepay-start="form-b"]').click();

    assert.equal(requests.length, 2);
    assert.equal(requests[1].configId, 'config-b');
    assert.equal(document.querySelector('#form-b [name="Moid"]').value, 'SP_config-b');
    assert.deepEqual(starts, ['form-a', 'form-b']);
    assert.equal(document.querySelector('.nicepay-standalone-form[name="payForm"]').id, 'form-b');

    environment.dom.window.close();
});

test('buyer validation enforces UTF-8 byte limits and focuses the first invalid field', () => {
    const environment = createEnvironment();
    const { window, requests } = environment;
    const document = window.document;
    const name = document.getElementById('form-a-visible-name');
    name.value = '한'.repeat(11);

    document.querySelector('[data-nicepay-start="form-a"]').click();

    assert.equal(requests.length, 0);
    assert.equal(document.activeElement, name);
    assert.equal(name.getAttribute('aria-invalid'), 'true');
    assert.equal(name.getAttribute('aria-describedby'), 'form-a-visible-name-error');
    assert.equal(document.getElementById('form-a-visible-name-error').textContent, 'This field exceeds the payment provider limit.');

    environment.dom.window.close();
});

test('standalone initialization rejects a provider response with an external return URL', () => {
    const environment = createEnvironment('https://attacker.example/capture');
    const { window, starts } = environment;
    const document = window.document;

    document.querySelector('[data-nicepay-start="form-a"]').click();

    assert.deepEqual(starts, []);
    assert.equal(document.querySelector('#form-a [name="ReturnURL"]').value, '');
    assert.equal(document.querySelector('#form-a-wrapper [role="alert"]').textContent, '!Payment initialization failed.');

    environment.dom.window.close();
});
