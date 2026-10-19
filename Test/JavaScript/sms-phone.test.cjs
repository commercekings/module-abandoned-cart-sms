const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

/** Loads the framework-free core (sms-phone-core.js) as the browser would through RequireJS. */
function core() {
    const source = fs.readFileSync(path.join(__dirname, '../../view/frontend/web/js/sms-phone-core.js'), 'utf8');
    let module;
    vm.runInContext(source, vm.createContext({define: (names, factory) => { module = factory(); }, JSON, Promise, String, Object}));
    return module;
}

function fetcher(status, body) {
    const calls = [];
    const fetch = (url, init) => {
        calls.push({url, init, body: JSON.parse(init.body)});
        return Promise.resolve({ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body)});
    };
    return {fetch, calls};
}

test('an international number is kept, with separators left out', () => {
    const {toE164} = core();
    assert.equal(toE164('+381 60 123-4567', 'DE'), '+381601234567');
    assert.equal(toE164(' +1 (212) 555.0100 ', 'RS'), '+12125550100');
    assert.equal(toE164('0044 7700 900123', 'RS'), '+447700900123', '00 is the international prefix');
});

test('a national number gets the country code and loses its trunk 0', () => {
    const {toE164} = core();
    assert.equal(toE164('060 123 4567', 'RS'), '+381601234567');
    assert.equal(toE164('07700 900123', 'gb'), '+447700900123', 'the country code in any case');
    assert.equal(toE164('0151 23456789', 'DE'), '+4915123456789');
});

test('North America: ten digits, or eleven with the trunk 1', () => {
    const {toE164} = core();
    assert.equal(toE164('(212) 555-0100', 'US'), '+12125550100');
    assert.equal(toE164('1 212 555 0100', 'CA'), '+12125550100');
});

test('Italy keeps its leading 0 after the country code', () => {
    const {toE164} = core();
    assert.equal(toE164('06 1234 5678', 'IT'), '+390612345678');
    assert.equal(toE164('333 123 4567', 'IT'), '+393331234567');
});

test('anything that cannot be a number is refused, like the server does', () => {
    const {toE164} = core();
    for (const [input, country] of [['', 'RS'], ['abc', 'RS'], ['+0601234567', 'RS'], ['+1234567', 'RS'],
        ['+1234567890123456', 'RS'], ['060 123 4567', ''], ['060 123 4567', 'XX'], ['whatsapp:+381601234567', 'RS'],
        ['++381601234567', 'RS'], ['12', 'RS']]) {
        assert.equal(toE164(input, country), '', JSON.stringify([input, country]));
    }
});

test('every calling code is digits and none starts with 0', () => {
    for (const [country, code] of Object.entries(core().codes)) {
        assert.match(country, /^[A-Z]{2}$/);
        assert.match(code, /^[1-9][0-9]{0,2}$/, country);
    }
});

test('the shipping phone is the number, read in the address\'s country, else the store\'s', () => {
    const {numberFor} = core();
    assert.deepEqual({...numberFor({addressPhone: '060 123 4567', addressCountry: 'RS', defaultCountry: 'DE'})},
        {number: '+381601234567', source: 'address'}, 'a national number of the shipping country');
    assert.equal(numberFor({addressPhone: '0151 23456789', addressCountry: '', defaultCountry: 'DE'}).number,
        '+4915123456789', 'no country yet: the store\'s');
    assert.equal(numberFor({addressPhone: '+44 7700 900123', addressCountry: 'RS', defaultCountry: 'DE'}).number,
        '+447700900123', 'an international number stays');
    assert.equal(numberFor({addressPhone: '(212) 555-0100', addressCountry: 'US'}).number, '+12125550100');
});

test('no usable shipping phone gives no number', () => {
    const {numberFor} = core();
    for (const phone of ['', '   ', '123', 'call me', undefined]) {
        assert.equal(numberFor({addressPhone: phone, addressCountry: 'RS', defaultCountry: 'RS'}).number, '',
            JSON.stringify(phone));
    }
});

test('a different number replaces the shipping phone, read the same way', () => {
    const {numberFor} = core();
    const state = {addressPhone: '060 123 4567', addressCountry: 'RS', defaultCountry: 'DE', useCustom: true};
    assert.deepEqual({...numberFor({...state, custom: '064 765 4321'})}, {number: '+381647654321', source: 'custom'},
        'e.g. a gift recipient\'s phone, national in the shipping country');
    assert.equal(numberFor({...state, custom: '+43 664 1234567'}).number, '+436641234567');
    assert.equal(numberFor({...state, custom: ''}).number, '', 'nothing typed yet: no number, not the shipping phone');
    assert.equal(numberFor({...state, custom: 'abc'}).number, '');
    assert.equal(numberFor({...state, useCustom: false, custom: '064 765 4321'}).number, '+381601234567',
        'switched back: the shipping phone again');
});

test('display shows the calling code apart, and nothing for a non-number', () => {
    const {display} = core();
    assert.equal(display('+381601234567'), '+381 601234567');
    assert.equal(display('+12125550100'), '+1 2125550100');
    assert.equal(display('+447700900123'), '+44 7700900123');
    assert.equal(display('+4915123456789'), '+49 15123456789');
    for (const value of ['', '060 123 4567', '+0123456789', null]) {
        assert.equal(display(value), '', JSON.stringify(value));
    }
});

test('subscribe posts the type, the cart and the number to Notification\'s route', async () => {
    const {fetch, calls} = fetcher(200, true);
    assert.equal(await core().subscribe({url: '/rest/default/V1/ck-notification/subscriptions', type: 'cart_reminder_sms',
        reference: 'masked123', recipient: '+381601234567', fetch}), true);
    assert.equal(calls[0].init.method, 'POST');
    assert.equal(calls[0].init.credentials, 'same-origin');
    assert.equal(calls[0].init.headers['X-Requested-With'], 'XMLHttpRequest');
    assert.deepEqual(calls[0].body, {type: 'cart_reminder_sms', reference: 'masked123', recipient: '+381601234567'});
});

test('a customer\'s quote id is sent as a string; a refusal or an error rejects', async () => {
    const {fetch, calls} = fetcher(200, true);
    await core().subscribe({url: '/mine', type: 't', reference: 42, recipient: '+1', fetch});
    assert.equal(calls[0].body.reference, '42');
    await assert.rejects(core().subscribe({url: '/u', type: 't', reference: 'r', recipient: '+1',
        fetch: fetcher(200, false).fetch}), /refused/);
    await assert.rejects(core().subscribe({url: '/u', type: 't', reference: 'r', recipient: '+1',
        fetch: fetcher(400, {message: 'nope'}).fetch}), /400/);
});
