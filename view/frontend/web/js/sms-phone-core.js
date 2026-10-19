/**
 * Text message reminders at checkout, framework-free: toE164() turns a typed number into the form Notification stores
 * ("+381601234567"), numberFor() gives the number the reminder goes to (the shipping phone), subscribe() signs the
 * cart up (Notification's address-consent-core shows the number in the checkbox wording). Luma's checkout uses it
 * (js/view/sms-consent); a custom checkout can build on it. Notification checks the number again on the server.
 */
define([], function () {
    'use strict';

    /** Country calling codes by ISO 3166 country; NANP countries share 1. */
    var CODES = {
        AD: '376', AE: '971', AF: '93', AG: '1', AI: '1', AL: '355', AM: '374', AO: '244', AR: '54', AS: '1',
        AT: '43', AU: '61', AW: '297', AX: '358', AZ: '994', BA: '387', BB: '1', BD: '880', BE: '32', BF: '226',
        BG: '359', BH: '973', BI: '257', BJ: '229', BL: '590', BM: '1', BN: '673', BO: '591', BQ: '599', BR: '55',
        BS: '1', BT: '975', BW: '267', BY: '375', BZ: '501', CA: '1', CD: '243', CF: '236', CG: '242', CH: '41',
        CI: '225', CK: '682', CL: '56', CM: '237', CN: '86', CO: '57', CR: '506', CU: '53', CV: '238', CW: '599',
        CY: '357', CZ: '420', DE: '49', DJ: '253', DK: '45', DM: '1', DO: '1', DZ: '213', EC: '593', EE: '372',
        EG: '20', ER: '291', ES: '34', ET: '251', FI: '358', FJ: '679', FK: '500', FM: '691', FO: '298', FR: '33',
        GA: '241', GB: '44', GD: '1', GE: '995', GF: '594', GG: '44', GH: '233', GI: '350', GL: '299', GM: '220',
        GN: '224', GP: '590', GQ: '240', GR: '30', GT: '502', GU: '1', GW: '245', GY: '592', HK: '852', HN: '504',
        HR: '385', HT: '509', HU: '36', ID: '62', IE: '353', IL: '972', IM: '44', IN: '91', IQ: '964', IR: '98',
        IS: '354', IT: '39', JE: '44', JM: '1', JO: '962', JP: '81', KE: '254', KG: '996', KH: '855', KI: '686',
        KM: '269', KN: '1', KR: '82', KW: '965', KY: '1', KZ: '7', LA: '856', LB: '961', LC: '1', LI: '423',
        LK: '94', LR: '231', LS: '266', LT: '370', LU: '352', LV: '371', LY: '218', MA: '212', MC: '377', MD: '373',
        ME: '382', MF: '590', MG: '261', MH: '692', MK: '389', ML: '223', MM: '95', MN: '976', MO: '853', MP: '1',
        MQ: '596', MR: '222', MS: '1', MT: '356', MU: '230', MV: '960', MW: '265', MX: '52', MY: '60', MZ: '258',
        NA: '264', NC: '687', NE: '227', NG: '234', NI: '505', NL: '31', NO: '47', NP: '977', NR: '674', NU: '683',
        NZ: '64', OM: '968', PA: '507', PE: '51', PF: '689', PG: '675', PH: '63', PK: '92', PL: '48', PM: '508',
        PR: '1', PS: '970', PT: '351', PW: '680', PY: '595', QA: '974', RE: '262', RO: '40', RS: '381', RU: '7',
        RW: '250', SA: '966', SB: '677', SC: '248', SD: '249', SE: '46', SG: '65', SI: '386', SK: '421', SL: '232',
        SM: '378', SN: '221', SO: '252', SR: '597', SS: '211', ST: '239', SV: '503', SX: '1', SY: '963', SZ: '268',
        TC: '1', TD: '235', TG: '228', TH: '66', TJ: '992', TL: '670', TM: '993', TN: '216', TO: '676', TR: '90',
        TT: '1', TV: '688', TW: '886', TZ: '255', UA: '380', UG: '256', US: '1', UY: '598', UZ: '998', VA: '39',
        VC: '1', VE: '58', VG: '1', VI: '1', VN: '84', VU: '678', WS: '685', XK: '383', YE: '967', YT: '262',
        ZA: '27', ZM: '260', ZW: '263'
    };

    /** Calling codes whose national numbers keep their leading 0 (Italy and the Vatican, San Marino). */
    var KEEP_ZERO = {39: true, 378: true};

    /** National trunk prefixes other than 0 (libphonenumber's): Hungary 06, Lithuania 0 or 8. */
    var TRUNKS = {36: /^06/, 370: /^[08]/};

    /**
     * Mobile national number lengths (without the trunk 0; libphonenumber's metadata): texts go to mobiles, so a
     * shorter number - a landline, or one still being typed - gets no checkbox. Countries not listed: MIN_NATIONAL.
     */
    var LENGTHS = {
        AL: [9, 9], AT: [7, 13], AU: [9, 9], BA: [8, 9], BE: [9, 9], BG: [8, 9], CA: [10, 10], CH: [9, 9],
        CZ: [9, 9], DE: [10, 11], DK: [8, 8], EE: [7, 8], ES: [9, 9], FI: [6, 10], FR: [9, 9], GB: [10, 10],
        GR: [10, 10], HR: [8, 9], HU: [9, 9], IE: [9, 9], IT: [9, 10], LI: [7, 9], LT: [8, 8], LU: [9, 9],
        LV: [8, 8], ME: [8, 8], MK: [8, 8], NL: [9, 11], NO: [8, 8], NZ: [8, 10], PL: [9, 9], PT: [9, 9],
        RO: [9, 9], RS: [8, 10], SE: [9, 9], SI: [8, 8], SK: [9, 9], TR: [10, 10], UA: [9, 9], US: [10, 10],
        XK: [8, 8]
    };
    var MIN_NATIONAL = 7;

    /** The calling codes (prefix-free: no code is the start of another). */
    var CALLING = {};
    Object.keys(CODES).forEach(function (country) { CALLING[CODES[country]] = true; });

    /** The same rule as Notification's PhoneNormalizer: "+", a country code not starting with 0, 8-15 digits. */
    var E164 = /^\+[1-9][0-9]{7,14}$/;

    /**
     * @param {string} input what the shopper typed: "+381 60 123 4567", "00381 60 123 4567" or a national number
     *     ("060 123 4567") of the given country
     * @param {string} country ISO 3166 code for a national number, e.g. the store's default country
     * @return {string} the number in E.164, '' when it cannot be one
     */
    function toE164(input, country) {
        // "+381 (0) 61 …": the trunk 0 some write in brackets after the country code is not dialled
        var raw = String(input || '').trim().replace(/^((?:\+|00)[\s\-.]*[1-9][0-9]{0,2})[\s\-.]*\(0\)/, '$1')
                .replace(/[\s\-.()\/]+/g, ''),
            digits;

        country = String(country || '').toUpperCase();
        if (/^\+/.test(raw)) {
            digits = raw.slice(1);
        } else if (/^00/.test(raw)) {
            digits = raw.slice(2);
        } else {
            digits = withCountryCode(raw, country);
        }
        return /^[0-9]+$/.test(digits || '') ? checked(digits, country) : '';
    }

    /** A number typed without + or 00: national (trunk 0 or not), or international with its country code. */
    function withCountryCode(national, country) {
        var code = CODES[country],
            rest;

        if (!code || !/^[0-9]+$/.test(national)) {
            return '';
        }
        if (code === '1' && national.length === 11 && national.charAt(0) === '1') {
            return code + national.slice(1); // a NANP number typed with its trunk 1
        }
        if (trunk(code).test(national)) {
            return code + national.replace(trunk(code), ''); // the national trunk prefix
        }
        if (national.indexOf(code) === 0 && national.length > lengthsFor(code, country)[1]) {
            // "381611234530" in Serbia: too long for a national number - the country code without + or 00
            rest = trunkless(national.slice(code.length), code);
            if (fits(rest, code, country)) {
                return code + rest;
            }
        }
        return code + national;
    }

    /** The national number after a country code: a trunk prefix typed after it ("+381 061 …") is dropped. */
    function trunkless(national, code) {
        return national.replace(trunk(code), '');
    }

    /** The country's national trunk prefix (none where national numbers keep their 0). */
    function trunk(code) {
        return KEEP_ZERO[code] ? /^(?!)/ : TRUNKS[code] || /^0/;
    }

    /** The national number's length is a mobile number's of the country (Italy's start with 0 or 3). */
    function fits(national, code, country) {
        var lengths = lengthsFor(code, country);

        return national.length >= lengths[0] && national.length <= lengths[1]
            && (code !== '39' || /^[03]/.test(national));
    }

    /** The lengths of the address's country when the number is of its code, else of the code's listed country. */
    function lengthsFor(code, country) {
        var listed = Object.keys(LENGTHS).filter(function (each) { return CODES[each] === code; });

        if (CODES[country] === code && LENGTHS[country]) {
            return LENGTHS[country];
        }
        return listed.length ? LENGTHS[listed[0]] : [MIN_NATIONAL, 15 - code.length];
    }

    /** "+" and the digits when they are a calling code and a national number of a fitting length; else ''. */
    function checked(digits, country) {
        var code = [1, 2, 3].map(function (size) { return digits.slice(0, size); })
                .filter(function (prefix) { return CALLING[prefix]; })[0],
            national;

        if (!code) {
            return '';
        }
        national = trunkless(digits.slice(code.length), code);
        return fits(national, code, country) && E164.test('+' + code + national) ? '+' + code + national : '';
    }

    /**
     * The number the text reminder goes to: the shipping address's phone, read in the address's country (else the
     * store's) - '' when it cannot take texts (empty, too short, not a number).
     *
     * @param {Object} state addressPhone, addressCountry (ISO 3166), defaultCountry
     * @return {string} the number in E.164, or ''
     */
    function numberFor(state) {
        return toE164(state.addressPhone, state.addressCountry || state.defaultCountry || '');
    }

    /**
     * Signs the cart up for the text reminder (Notification's subscribe route): the guest's masked cart id, or the
     * customer's quote id with /mine; the number must already have its consent (the checkbox).
     *
     * @param {Object} options url, type, reference, recipient, fetch (optional, defaults to window.fetch)
     * @return {Promise<boolean>} resolves true when stored; rejects when refused or failed
     */
    function subscribe(options) {
        var send = options.fetch || window.fetch.bind(window);

        return send(options.url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
            body: JSON.stringify({
                type: options.type,
                reference: String(options.reference),
                recipient: options.recipient
            })
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('Text reminder not stored (' + response.status + ')');
            }
            return response.json();
        }).then(function (result) {
            if (result !== true) {
                throw new Error('Text reminder refused');
            }
            return true;
        });
    }

    return {toE164: toE164, numberFor: numberFor, subscribe: subscribe, codes: CODES};
});
