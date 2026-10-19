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

test('a trunk 0 in brackets after the country code is not dialled', () => {
    const {toE164} = core();
    assert.equal(toE164('+381 (0) 61 123 4530', 'RS'), '+381611234530');
    assert.equal(toE164('+381(0)611234530', 'DE'), '+381611234530');
    assert.equal(toE164('0044 (0)7700 900123', 'RS'), '+447700900123');
    assert.equal(toE164('+44 (0) 7700-900123', 'GB'), '+447700900123');
    assert.equal(toE164('+381 061 123 4530', 'RS'), '+381611234530', 'nor a trunk 0 typed without brackets');
    assert.equal(toE164('+39 06 1234 5678', 'IT'), '+390612345678', 'Italy keeps its 0');
});

test('a country code typed without + or 00 is not added twice', () => {
    const {toE164} = core();
    assert.equal(toE164('381611234530', 'RS'), '+381611234530');
    assert.equal(toE164('381 61 123 4530', 'RS'), '+381611234530');
    assert.equal(toE164('3810611234530', 'RS'), '+381611234530', 'with the trunk 0 after it');
    assert.equal(toE164('4915123456789', 'DE'), '+4915123456789');
    assert.equal(toE164('447700900123', 'GB'), '+447700900123');
    assert.equal(toE164('393331234567', 'IT'), '+393331234567');
    assert.equal(toE164('3912345678', 'IT'), '+393912345678', 'an Italian mobile starting with 391: a national number');
    assert.equal(toE164('912 345 6789', 'IN'), '+919123456789', 'an Indian mobile starting with 91: a national number');
});

test('a number shorter than a mobile number of its country gives none, so a partial number never passes', () => {
    const {toE164} = core();
    for (const [input, country] of [['123 4530', 'RS'], ['1234530', 'RS'], ['555 1234', 'RS'], ['061 123 45', 'RS'],
        ['+3811234530', 'RS'], ['+381 61 27', 'DE'], ['0151 234567', 'DE'], ['07700 9001', 'GB'], ['06 12 34 56', 'FR'],
        ['091 234 56', 'HR'], ['061 234 56', 'BA'], ['031 234 5', 'SI'], ['0664 12', 'AT'], ['212 555 010', 'US'],
        ['06 1234 567', 'NL'], ['333 123', 'IT'], ['+44 7700 9001', 'RS'], ['+1 212 555 010', 'RS']]) {
        assert.equal(toE164(input, country), '', JSON.stringify([input, country]));
    }
});

test('a mobile number of each listed country passes, and other countries need seven national digits', () => {
    const {toE164} = core();
    for (const [input, country, expected] of [
        ['061 123 4530', 'RS', '+381611234530'], ['063 123 456', 'RS', '+38163123456'],
        ['0664 1234567', 'AT', '+436641234567'], ['091 234 5678', 'HR', '+385912345678'],
        ['061 234 567', 'BA', '+38761234567'], ['031 234 567', 'SI', '+38631234567'],
        ['06 12 34 56 78', 'FR', '+33612345678'], ['06 12345678', 'NL', '+31612345678'],
        ['(416) 555-0100', 'CA', '+14165550100'], ['+41 79 123 45 67', 'RS', '+41791234567'],
        ['8123 4567', 'SG', '+6581234567']]) {
        assert.equal(toE164(input, country), expected, JSON.stringify([input, country]));
    }
    assert.equal(toE164('812 345', 'SG'), '', 'six national digits: too short where no length is listed');
    assert.equal(toE164('+65 812 345', 'RS'), '', 'also after a calling code of an unlisted country');
});

test('a too long number of a listed country gives none', () => {
    const {toE164} = core();
    assert.equal(toE164('061 123 4530 12', 'RS'), '');
    assert.equal(toE164('+44 7700 9001234', 'RS'), '');
});

test('every calling code is digits and none starts with 0', () => {
    for (const [country, code] of Object.entries(core().codes)) {
        assert.match(country, /^[A-Z]{2}$/);
        assert.match(code, /^[1-9][0-9]{0,2}$/, country);
    }
});

test('the shipping phone is the number, read in the address\'s country, else the store\'s', () => {
    const {numberFor} = core();
    assert.equal(numberFor({addressPhone: '060 123 4567', addressCountry: 'RS', defaultCountry: 'DE'}), '+381601234567',
        'a national number of the shipping country');
    assert.equal(numberFor({addressPhone: '0151 23456789', addressCountry: '', defaultCountry: 'DE'}), '+4915123456789',
        'no country yet: the store\'s');
    assert.equal(numberFor({addressPhone: '+44 7700 900123', addressCountry: 'RS', defaultCountry: 'DE'}), '+447700900123',
        'an international number stays');
    assert.equal(numberFor({addressPhone: '(212) 555-0100', addressCountry: 'US'}), '+12125550100');
});

test('a shipping phone that cannot take texts gives no number (no checkbox)', () => {
    const {numberFor} = core();
    for (const phone of ['', '   ', '123', 'call me', undefined]) {
        assert.equal(numberFor({addressPhone: phone, addressCountry: 'RS', defaultCountry: 'RS'}), '', JSON.stringify(phone));
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
