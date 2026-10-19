const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const js = (file) => fs.readFileSync(path.join(__dirname, '../../view/frontend/web', file), 'utf8');

/**
 * A file of Notification's (the address consent core and widget): its repository next to this one (as the checks
 * check them out, and as in a development checkout), else the Magento installation's vendor copy.
 */
function notificationFile(file) {
    const candidates = [path.join(__dirname, '../../../module-notification', file)];
    if (process.env.MAGENTO_ROOT) {
        candidates.push(path.join(process.env.MAGENTO_ROOT, 'vendor/commercekings/module-notification', file));
    }
    const found = candidates.find((candidate) => fs.existsSync(candidate));
    assert.ok(found, 'module-notification is needed next to this repository or in MAGENTO_ROOT/vendor');
    return fs.readFileSync(found, 'utf8');
}

/** Notification's address-consent-core: the checkbox wording with the number. */
function notificationCore() {
    let module;
    vm.runInContext(notificationFile('view/frontend/web/js/address-consent-core.js'), vm.createContext({
        define: (deps, factory) => { module = factory(); }, JSON, Promise, String, Object, Math
    }));
    return module;
}

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
    const isObservable = (o) => typeof o === 'function' && typeof o.subscribe === 'function' && !o.check;
    return {observable, pureComputed, isObservable};
}

/**
 * The rendered block with Notification's real consent widget on it, behind a minimal jQuery: the hidden number field
 * (bound to number in the template), the checkbox, the label. The widget's server calls (core.record) are recorded in
 * state.posts: {recipient, given} - a consent recorded (given) or withdrawn.
 */
function dom() {
    const state = {hidden: '', checked: false, changes: [], widget: null, handlers: {}, posts: [], instance: null};
    const listeners = {box: [], document: []};
    const hidden = {
        val(v) { if (v === undefined) { return state.hidden; } state.hidden = v; return hidden; },
        trigger(e) {
            state.changes.push(['hidden', e, state.hidden]);
            listeners.document.filter((l) => l.selector === '#ck-sms-phone-number').forEach((l) => l.fn({type: e}));
            return hidden;
        }
    };
    const box = {
        prop(name, v) { if (v === undefined) { return state.checked; } state.checked = v; return box; },
        on(e, fn) { listeners.box.push(fn); return box; },
        trigger(e) {
            state.changes.push(['box', e, state.checked]);
            listeners.box.forEach((fn) => fn({type: e}));
            return box;
        }
    };
    const label = {text() { return label; }, first() { return label; }};
    const doc = {
        on(e, selector, fn) { listeners.document.push({selector, fn}); return doc; },
        off() { return doc; }
    };
    const root = {
        find: (selector) => ({'#ck-sms-phone-number': hidden, '#ck-address-consent-cart_reminder_sms': box,
            'input[type="checkbox"]': box, 'label span': label})[selector],
        ckAddressConsent(options) {
            state.widget = options;
            const instance = state.instance = Object.create(widget);
            Object.assign(instance, {element: root, uuid: 1, options: {...widget.options, ...options}});
            instance._create();
            return root;
        },
        on(event, cb) { state.handlers[event] = cb; return root; },
        trigger(event, args) {
            if (state.handlers[event]) { state.handlers[event]({type: event}, ...(args || [])); }
            return root;
        }
    };
    const document = {};
    const $ = (target) => (target === document ? doc : target === '#ck-sms-phone-number' ? hidden : root);
    let widget;
    $.widget = (name, definition) => { widget = definition; $.commercekings = {ckAddressConsent: definition}; };
    const core = {...notificationCore(), record(options) {
        state.posts.push({recipient: options.recipient, given: options.given});
        return Promise.resolve(true);
    }};
    vm.runInContext(notificationFile('view/frontend/web/js/address-consent.js'), vm.createContext({
        define: (names, factory) => factory($, core), document, Promise, String,
        window: {checkoutConfig: {quoteData: {entity_id: 'masked-1'}}}
    }));
    /** the widget's server calls are queued: let them run */
    const settle = async () => {
        for (let i = 0; i < 5; i++) { await state.instance.queue; await new Promise(setImmediate); }
    };
    return {state, root, $, settle};
}

/**
 * The component built the way Magento_Ui's layout.js build() does: config merged into the node, then "type" taken
 * for componentType and deleted, "config" deleted.
 */
function component(config, {provider = null, address = null, phoneField = undefined} = {}) {
    const ko = knockout();
    const page = dom();
    // the address form's phone field, a sibling in the fieldset: its focus decides when the number is taken
    const field = phoneField === undefined ? {focused: ko.observable(false)} : phoneField;
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
    const fieldset = 'checkout.steps.shipping-step.shippingAddress.shipping-address-fieldset';
    const registry = {async: (name) => (cb) => {
        if (name === 'checkoutProvider') {
            cb(provider);
        } else if (name === fieldset + '.telephone' && field) {
            cb(field);
        }
    }};
    const window = {checkoutConfig: {quoteData: {entity_id: 'masked-1'}, isCustomerLoggedIn: false}};
    vm.runInContext(js('js/view/sms-consent.js'), vm.createContext({
        define: (deps, factory) => factory(page.$, ko, uiComponent, registry, quote, core, notificationCore()), window, String
    }));
    const node = {component: 'CommerceKings_AbandonedCartSms/js/view/sms-consent', config};
    Object.assign(node, node.config,
        {index: 'ck-sms-consent', name: fieldset + '.ck-sms-consent', parentName: fieldset});
    node.componentType = node.type;
    delete node.type;
    delete node.config;
    const instance = new Component(node);
    // the template's value binding on the hidden field
    page.state.hidden = instance.number();
    instance.number.subscribe((value) => { page.state.hidden = value; });
    instance.initConsent(page.root);
    return {instance, page, quote, posts, field};
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

/** The shopper edits the phone in place: focus, every intermediate value reaches the form, then the field is left. */
function edit(c, provider, values) {
    c.field.focused(true);
    values.forEach((value) => provider.set('telephone', value));
    c.field.focused(false);
}

const CONFIG = {
    subscriptionType: 'cart_reminder_sms',
    country: 'DE',
    urls: {guest: '/rest/default/V1/ck-notification/subscriptions', mine: '/rest/default/V1/ck-notification/subscriptions/mine'},
    consent: {wording: 'Yes, text me offers at {{number}}. Reply STOP to stop.', wordingHash: 'h1',
        urls: {guest: '/consents', mine: '/consents/mine'}},
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

/** The shopper ticks the box: the widget records the consent for the number. */
async function tick(page) {
    page.state.checked = true;
    page.root.find('#ck-address-consent-cart_reminder_sms').trigger('change');
    await page.settle();
}

test('the shipping phone of the address form is the number, shown in the one-line label', () => {
    const provider = form('060 123 4567', 'RS');
    const {instance} = component(CONFIG, {provider});
    assert.equal(instance.number(), '+381601234567');
    assert.equal(instance.label(), 'Yes, text me offers at +381 60 123 4567. Reply STOP to stop.',
        'one line: the configured wording with the number, grouped');
    const template = js('template/sms-consent.html');
    assert.match(template, /<label class="label" for="ck-address-consent-cart_reminder_sms">\s*<span data-bind="text: label"><\/span>\s*<\/label>/);
    assert.doesNotMatch(template, /Yes, text me at|ck-sms-consent-wording|note/, 'no second line');
});

test('editing the number in place while ticked: nothing per keystroke, then withdrawn and unticked', async () => {
    const provider = form('061 123 4530', 'RS');
    const c = component(CONFIG, {provider});
    await tick(c.page);
    assert.deepEqual(c.page.state.posts, [{recipient: '+381611234530', given: true}]);
    c.page.state.changes = [];
    // each step of editing "061 123 4530" in place used to be taken as a number (+38111234530, +3811234530, ...)
    c.field.focused(true);
    for (const value of ['01 123 4530', '0 123 4530', '0 123 453', '064 123 453', '064 123 4531']) {
        provider.set('telephone', value);
        assert.equal(c.instance.number(), '+381611234530', 'still the number the consent is for: ' + value);
    }
    await c.page.settle();
    assert.deepEqual(c.page.state.changes, [], 'nothing while typing');
    assert.equal(c.page.state.checked, true);
    c.field.focused(false);
    await c.page.settle();
    assert.equal(c.instance.number(), '+381641234531');
    assert.equal(c.page.state.checked, false, 'unticked: the shopper ticks again for the new number');
    assert.deepEqual(c.page.state.posts, [{recipient: '+381611234530', given: true},
        {recipient: '+381611234530', given: false}], 'the old consent withdrawn, no consent for the new number');
    assert.deepEqual(c.page.state.changes.slice(0, 2),
        [['box', 'change', false], ['hidden', 'change', '+381641234531']],
        'unticked before the widget sees the new number');
    assert.equal(c.instance.label(), 'Yes, text me offers at +381 64 123 4531. Reply STOP to stop.');
});

test('a re-tick records the new number - only then', async () => {
    const provider = form('061 123 4530', 'RS');
    const c = component(CONFIG, {provider});
    await tick(c.page);
    edit(c, provider, ['064 123 4531']);
    await c.page.settle();
    assert.equal(c.page.state.checked, false, 'a full new number after leaving the field: still unticked');
    assert.equal(c.page.state.posts.length, 2);
    await tick(c.page);
    assert.deepEqual(c.page.state.posts.slice(2), [{recipient: '+381641234531', given: true}]);
});

test('a country change while ticked: the consent is withdrawn and the box unticked, nothing recorded', async () => {
    const provider = form('061 123 4530', 'RS');
    const c = component(CONFIG, {provider});
    await tick(c.page);
    provider.set('country_id', 'BA');
    await c.page.settle();
    assert.equal(c.instance.number(), '+387611234530');
    assert.equal(c.page.state.checked, false);
    assert.deepEqual(c.page.state.posts, [{recipient: '+381611234530', given: true},
        {recipient: '+381611234530', given: false}]);
});

test('a number cleared or no longer textable while ticked: withdrawn, unticked, no checkbox', async () => {
    const provider = form('061 123 4530', 'RS');
    const c = component(CONFIG, {provider});
    await tick(c.page);
    edit(c, provider, ['060 123', '123 4530']);
    await c.page.settle();
    assert.equal(c.instance.number(), '', '123 4530 is too short for a Serbian mobile');
    assert.equal(c.page.state.checked, false);
    assert.deepEqual(c.page.state.posts.slice(1), [{recipient: '+381611234530', given: false}]);
});

test('the same number typed again in another way changes nothing', async () => {
    const provider = form('061 123 4530', 'RS');
    const c = component(CONFIG, {provider});
    await tick(c.page);
    edit(c, provider, ['06', '+381 61 123 4530']);
    await c.page.settle();
    assert.equal(c.page.state.checked, true);
    assert.equal(c.page.state.posts.length, 1);
});

test('a phone filled in without focus (autofill, a script) is taken at once; a ticked box is unticked', async () => {
    const provider = form('', 'RS');
    const c = component(CONFIG, {provider});
    provider.set('telephone', '061 123 4530');
    assert.equal(c.instance.number(), '+381611234530');
    await tick(c.page);
    provider.set('telephone', '064 123 4531');
    await c.page.settle();
    assert.equal(c.page.state.checked, false);
    assert.equal(c.page.state.posts.filter((p) => p.given).length, 1, 'never recorded on the shopper\'s behalf');
});

test('without a phone field to watch (another checkout), the phone is taken as it changes', () => {
    const provider = form('061 123 4530', 'RS');
    const c = component(CONFIG, {provider, phoneField: null});
    provider.set('telephone', '064 123 4531');
    assert.equal(c.instance.number(), '+381641234531');
});

test('a saved address: the selected address\'s phone; another address while ticked unticks', async () => {
    const c = component({...CONFIG, placement: 'address-list'},
        {address: {telephone: '+44 7700 900123', countryId: 'GB'}});
    assert.equal(c.instance.number(), '+447700900123');
    await tick(c.page);
    c.quote.shippingAddress({telephone: '07700 900456', countryId: 'GB'});
    await c.page.settle();
    assert.equal(c.instance.number(), '+447700900456');
    assert.equal(c.page.state.checked, false);
    assert.deepEqual(c.page.state.posts, [{recipient: '+447700900123', given: true},
        {recipient: '+447700900123', given: false}]);
});

test('only the shipping phone: no different-number link or input, and no checkbox without a usable phone', () => {
    const template = js('template/sms-consent.html');
    assert.doesNotMatch(template, /toggle|custom|different/i);
    assert.match(template, /<div data-bind="visible: number">\s*<input type="checkbox"/);
    const provider = form('123', 'RS');
    const {instance} = component(CONFIG, {provider});
    assert.equal(instance.number(), '', 'too short: no checkbox');
    provider.set('telephone', '060 123 4567');
    assert.equal(instance.number(), '+381601234567');
});

test('the label follows the number; a wording without {{number}} is shown as it is, and its hash is sent unchanged', () => {
    const provider = form('060 123 4567', 'RS');
    const {instance, page} = component(CONFIG, {provider});
    provider.set('telephone', '061 123 4530');
    assert.equal(instance.label(), 'Yes, text me offers at +381 61 123 4530. Reply STOP to stop.');
    assert.equal(page.state.widget.wordingHash, 'h1', 'the hash of the configured wording, not of the label');
    const old = component({...CONFIG, consent: {...CONFIG.consent, wording: 'Yes, text me offers at this number.'}},
        {provider: form('060 123 4567', 'RS')});
    assert.equal(old.instance.label(), 'Yes, text me offers at this number.');
});
