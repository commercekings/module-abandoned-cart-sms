const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const js = (file) => fs.readFileSync(path.join(__dirname, '../../view/frontend/web', file), 'utf8');

/** A minimal knockout: observables, and computeds re-evaluated on every write (enough for this component). */
function knockout() {
    const computeds = [];
    const notify = () => computeds.forEach((c) => c.check());
    const observable = (value) => {
        const listeners = [];
        const o = function (next) {
            if (arguments.length) {
                if (next !== value) {
                    value = next;
                    listeners.forEach((cb) => cb(value));
                    notify();
                }
                return undefined;
            }
            return value;
        };
        o.subscribe = (cb) => listeners.push(cb);
        return o;
    };
    const pureComputed = (fn) => {
        const listeners = [];
        let last = fn();
        const c = () => fn();
        c.subscribe = (cb) => listeners.push(cb);
        c.check = () => {
            const now = fn();
            if (now !== last) {
                last = now;
                listeners.forEach((cb) => cb(now));
            }
        };
        computeds.push(c);
        return c;
    };
    return {observable, pureComputed};
}

/** The rendered block: the hidden number field (bound to number in the template), the checkbox, the widget. */
function dom() {
    const state = {hidden: '', checked: false, changes: [], widget: null, handlers: {}};
    const hidden = {
        val(v) { if (v === undefined) { return state.hidden; } state.hidden = v; return hidden; },
        trigger(e) { state.changes.push(['hidden', e, state.hidden]); return hidden; }
    };
    const box = {
        prop(name, v) { if (v === undefined) { return state.checked; } state.checked = v; return box; },
        trigger(e) { state.changes.push(['box', e, state.checked]); return box; }
    };
    const root = {
        find: (selector) => ({'#ck-sms-phone-number': hidden, '#ck-address-consent-cart_reminder_sms': box})[selector],
        ckAddressConsent(options) { state.widget = options; return root; },
        on(event, cb) { state.handlers[event] = cb; return root; }
    };
    return {state, root, $: () => root};
}

/**
 * The component built the way Magento_Ui's layout.js build() does: config merged into the node, then "type" taken
 * for componentType and deleted, "config" deleted.
 */
function component(config, {provider = null, address = null} = {}) {
    const ko = knockout();
    const page = dom();
    const quote = {shippingAddress: ko.observable(address)};
    const posts = [];
    let core;
    vm.runInContext(js('js/sms-phone-core.js'), vm.createContext({
        define: (deps, factory) => { core = factory(); }, JSON, Promise, String, Object
    }));
    core.subscribe = (options) => { posts.push(options); return Promise.resolve(true); };
    let Component;
    const uiComponent = {extend: (proto) => (Component = function (node) {
        Object.assign(this, JSON.parse(JSON.stringify(proto.defaults)), node);
        Object.keys(proto).forEach((key) => { if (key !== 'defaults') { this[key] = proto[key]; } });
        this._super = () => this;
        this.initialize();
    })};
    const registry = {async: () => (cb) => cb(provider)};
    const window = {checkoutConfig: {quoteData: {entity_id: 'masked-1'}, isCustomerLoggedIn: false}};
    vm.runInContext(js('js/view/sms-consent.js'), vm.createContext({
        define: (deps, factory) => factory(page.$, ko, uiComponent, registry, quote, core), window, String
    }));
    const node = {component: 'CommerceKings_AbandonedCartSms/js/view/sms-consent', config};
    Object.assign(node, node.config, {index: 'ck-sms-consent', name: 'checkout.steps.ck-sms-consent'});
    node.componentType = node.type;
    delete node.type;
    delete node.config;
    const instance = new Component(node);
    instance.initConsent(page.root);
    return {instance, page, quote, posts};
}

/** A guest's address form (checkoutProvider): set a field and notify, as the form does on change. */
function form(telephone, country) {
    const data = {shippingAddress: {telephone, country_id: country}};
    const listeners = [];
    return {
        get: (p) => p.split('.').reduce((o, k) => (o || {})[k], data),
        on: (name, cb) => listeners.push(cb),
        set(field, value) { data.shippingAddress[field] = value; listeners.forEach((cb) => cb()); }
    };
}

const CONFIG = {
    subscriptionType: 'cart_reminder_sms',
    country: 'DE',
    urls: {guest: '/rest/default/V1/ck-notification/subscriptions', mine: '/rest/default/V1/ck-notification/subscriptions/mine'},
    consent: {wording: 'Yes, text me.', wordingHash: 'h1', urls: {guest: '/consents', mine: '/consents/mine'}},
    placement: 'address-form'
};

test('the notification type survives Magento\'s layout builder and reaches the widget and the sign-up', async () => {
    const {page, posts} = component(CONFIG, {provider: form('060 123 4567', 'RS')});
    assert.equal(page.state.widget.type, 'cart_reminder_sms', 'not "" (a "type" key is taken by layout.js)');
    assert.equal(page.state.widget.wordingHash, 'h1');
    assert.equal(page.state.widget.phone, '#ck-sms-phone-number');
    page.state.handlers['ckAddressConsent:recorded']({}, {given: true, recipient: '+381601234567'});
    assert.deepEqual(posts.map((p) => ({...p})), [{url: CONFIG.urls.guest, type: 'cart_reminder_sms',
        reference: 'masked-1', recipient: '+381601234567'}]);
});

test('a config key named "type" would not arrive: layout.js takes it', () => {
    const {page} = component({...CONFIG, subscriptionType: undefined, type: 'cart_reminder_sms'},
        {provider: form('060 123 4567', 'RS')});
    assert.notEqual(page.state.widget.type, 'cart_reminder_sms');
});

test('the hidden number field is bound to the number in the template, so it is set before the first tick', () => {
    const template = js('template/sms-consent.html');
    assert.match(template, /<input type="hidden" id="ck-sms-phone-number" data-bind="value: number"\/>/);
    assert.match(template, /<input type="checkbox" class="checkbox" id="ck-address-consent-cart_reminder_sms"\/>/);
});

test('the shipping phone of the address form is the number, and a new one moves the consent', () => {
    const provider = form('060 123 4567', 'RS');
    const {instance, page} = component(CONFIG, {provider});
    assert.equal(instance.number(), '+381601234567');
    assert.equal(instance.shown(), '+381 601234567');
    page.state.checked = true;
    provider.set('telephone', '064 765 4321');
    assert.equal(page.state.hidden, '+381647654321');
    assert.deepEqual(page.state.changes, [['hidden', 'change', '+381647654321']], 'the widget moves the consent');
    assert.equal(page.state.checked, true);
    provider.set('telephone', '');
    assert.equal(page.state.checked, false, 'no number: unchecked, which withdraws');
});

test('a saved address: the selected address\'s phone', () => {
    const {instance, quote} = component({...CONFIG, placement: 'address-list'},
        {address: {telephone: '+44 7700 900123', countryId: 'GB'}});
    assert.equal(instance.number(), '+447700900123');
    quote.shippingAddress({telephone: '07700 900456', countryId: 'GB'});
    assert.equal(instance.number(), '+447700900456');
});

test('"Use a different number" keeps the ticked consent until a valid number is entered, then moves it', () => {
    const {instance, page} = component(CONFIG, {provider: form('060 123 4567', 'RS')});
    page.state.checked = true;
    instance.toggleCustom();
    assert.equal(instance.number(), '+381601234567', 'opening the field changes nothing');
    instance.custom('064');
    assert.equal(instance.number(), '+381601234567', 'not a number yet: the shipping phone stays');
    assert.equal(instance.customInvalid(), true);
    assert.deepEqual(page.state.changes, [], 'nothing withdrawn');
    instance.custom('064 765 4321');
    assert.equal(instance.number(), '+381647654321');
    assert.equal(instance.customInvalid(), false);
    assert.deepEqual(page.state.changes, [['hidden', 'change', '+381647654321']]);
    assert.equal(page.state.checked, true, 'still ticked: the widget withdraws the old number and records the new');
    instance.toggleCustom();
    assert.equal(instance.number(), '+381601234567', 'back to the shipping phone');
});
