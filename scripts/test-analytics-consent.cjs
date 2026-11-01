// Isolated consent lifecycle: fake DOM/storage/cookies only. No browser/network.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const templates = path.resolve(__dirname, '../public_html/modules/custom/nelkano_home/templates');
const input = process.argv[2] || path.join(templates, 'nelkano-analytics.html.twig');
const twig = fs.readFileSync(input, 'utf8');
const bannerTwig = fs.readFileSync(path.join(path.dirname(input), 'nelkano-consent-banner.html.twig'), 'utf8');
const STORAGE = 'nelkano_analytics_consent';
const INTERNAL = 'nelkano_analytics_internal';
const VERSION = '2026-10-31.1';
const TTL = 730 * 86400000;
const START = Date.UTC(2026, 9, 31, 12);
let assertions = 0;
function check(value, message) { assert.ok(value, message); assertions++; }
function saved(choice, date = START) {
  return JSON.stringify({version: VERSION, choice, date: new Date(date).toISOString(), expiresAt: new Date(date + TTL).toISOString()});
}
function harness({raw, store = new Map(), internal = false, href = 'https://www.nelkano.com/en/releases?email=private@example.com#token'} = {}) {
  if (raw !== undefined) store.set(STORAGE, raw);
  const location = new URL(href);
  let clock = START, writesBlocked = false, nextTimer = 0;
  const timers = new Map(), scripts = [], cookieWrites = [];
  let cookies = [];
  const listeners = new Map(), windowListeners = new Map();
  const accept = {click: null}, reject = {click: null}, settings = {click: null};
  for (const button of [accept, reject, settings]) button.addEventListener = (name, fn) => {button[name] = fn;};
  const banner = {hidden: true, querySelector: name => name.includes('accept') ? accept : reject};
  const document = {
    referrer: 'https://example.org/source?email=referrer@example.com#secret',
    head: {appendChild: script => scripts.push(script)},
    createElement: () => ({}),
    querySelector: () => banner,
    querySelectorAll: () => [settings],
    addEventListener: (name, fn) => listeners.set(name, fn)
  };
  function visible(cookie) {
    return (location.hostname === cookie.domain || location.hostname.endsWith('.' + cookie.domain))
      && (location.pathname === cookie.path || location.pathname.startsWith(cookie.path.endsWith('/') ? cookie.path : cookie.path + '/'));
  }
  Object.defineProperty(document, 'cookie', {
    get: () => cookies.filter(visible).map(cookie => cookie.name + '=' + cookie.value).join('; '),
    set: value => {
      cookieWrites.push(value);
      const parts = value.split(';').map(part => part.trim());
      const name = parts[0].split('=')[0];
      const attrs = Object.fromEntries(parts.slice(1).map(part => {const at = part.indexOf('='); return [part.slice(0, at).toLowerCase(), part.slice(at + 1)];}));
      const domain = (attrs.domain || location.hostname).replace(/^\./, '');
      const cookiePath = attrs.path || '/';
      if (attrs['max-age'] === '0') cookies = cookies.filter(cookie => cookie.name !== name || cookie.domain !== domain || cookie.path !== cookiePath);
    }
  });
  const window = {
    location,
    localStorage: {
      getItem: key => store.has(key) ? store.get(key) : null,
      setItem: (key, value) => {if (writesBlocked) throw Error('storage unavailable'); store.set(key, value);},
      removeItem: key => {if (writesBlocked) throw Error('storage unavailable'); store.delete(key);}
    },
    setTimeout: (fn, delay) => {const id = ++nextTimer; timers.set(id, {fn, delay}); return id;},
    clearTimeout: id => timers.delete(id),
    addEventListener: (name, fn) => windowListeners.set(name, fn)
  };
  class TestDate extends Date {
    constructor(...args) { super(...(args.length ? args : [clock])); }
    static now() { return clock; }
  }
  const script = twig.match(/<script>([\s\S]*?)<\/script>/)[1]
    .replace("{{ analytics.ga_id|e('js') }}", 'G-TEST123')
    .replace("{{ analytics.exclude_internal|default(false) ? 'true' : 'false' }}", internal ? 'true' : 'false');
  check(!script.includes('{{'), 'All executable Twig fields are controlled by this harness');
  cookies = [
    {name: '_ga', value: 'old', domain: 'nelkano.com', path: '/'},
    {name: '_ga_TEST123', value: 'old', domain: 'nelkano.com', path: '/en'},
    {name: '_ga_TEST123', value: 'old', domain: location.hostname, path: '/en/'},
    {name: 'SSESS_app', value: 'session', domain: location.hostname, path: '/'},
    {name: 'theme', value: 'dark', domain: location.hostname, path: '/'}
  ];
  vm.runInNewContext(script, {window, document, URL, Date: TestDate}, {timeout: 1000});
  listeners.get('DOMContentLoaded')();
  return {
    window, banner, accept, reject, settings, store, scripts, cookieWrites,
    cookies: () => cookies,
    seedGaCookies: () => {cookies.push({name:'_ga',value:'new',domain:'nelkano.com',path:'/'});},
    blockWrites: () => {writesBlocked = true;},
    expire: () => {clock = START + TTL + 1; const timer = [...timers.values()][0]; check(!!timer, 'Consent expiry has a scheduled check'); timer.fn();},
    external: (key, value) => {if (value === null) store.delete(key); else store.set(key, value); windowListeners.get('storage')({key, newValue:value});},
    clickDownload: href => {
      const link = {getAttribute: name => name === 'href' ? href : null, hasAttribute: () => false};
      listeners.get('click')({target:{closest: () => link}});
    },
    commands: () => (window.dataLayer || []).map(args => Array.from(args))
  };
}

// One lifecycle plus navigation/storage boundaries of the same consent contract.
check(bannerTwig.includes('/privacidad-cookies#section-5') && bannerTwig.includes('/en/privacy-cookies#section-5'), 'Visible privacy link targets Cookies in ES and EN');
const first = harness({raw:'granted'});
check(first.scripts.length === 0 && !first.banner.hidden && !first.store.has(STORAGE), 'Undated legacy acceptance cannot load GA and asks for a new choice');
check(first.cookies().length === 2 && first.cookies().some(cookie => cookie.name === 'SSESS_app'), 'Only GA cookies are cleared, including parent-domain and ancestor-path copies');
check(first.cookieWrites.every(value => /^_ga(?:_|=)/.test(value)), 'No unrelated cookie deletion is attempted');
first.accept.click();
const consent = JSON.parse(first.store.get(STORAGE));
check(consent.choice === 'granted' && consent.version === VERSION && Date.parse(consent.expiresAt) - Date.parse(consent.date) === TTL, 'Acceptance is dated/versioned with the technical 730-day expiry');
check(first.scripts.length === 1 && first.window['ga-disable-G-TEST123'] === false, 'GA script is appended only after acceptance');
const config = first.commands().find(command => command[0] === 'config')[2];
check(config.cookie_expires === 63072000 && config.cookie_update === false && config.send_page_view === false, 'Explicit GA cookie lifetime, no rolling refresh, no duplicate automatic page view');
const page = first.commands().find(command => command[1] === 'page_view')[2];
check(page.page_location === 'https://www.nelkano.com/en/releases' && page.page_referrer === 'https://example.org/source', 'Page location/referrer omit query strings and fragments');
first.clickDownload('/descargar/en/windows/3.0.4/instalador?token=private#secret');
const download = first.commands().filter(command => command[1] === 'nelkano_download_click');
check(download.length === 1 && download[0][2].download_platform === 'windows' && download[0][2].download_version === '3.0.4' && download[0][2].link_url === 'https://www.nelkano.com/descargar/en/windows/3.0.4/instalador', 'One download event preserves platform/version and strips URL secrets');
first.seedGaCookies();
first.reject.click();
const rejectedLength = first.commands().length;
first.clickDownload('/descargar/es/android/3.0.4');
first.window.gtag('event', 'late_callback');
check(first.window['ga-disable-G-TEST123'] === true && first.commands().length === rejectedLength && first.cookies().every(cookie => !cookie.name.startsWith('_ga')), 'Rejection disables the loaded tag, clears GA cookies and blocks late/direct or download events');
first.external(STORAGE, saved('granted'));
check(first.scripts.length === 1 && first.window['ga-disable-G-TEST123'] === false, 'A valid new choice from another tab is recognized without loading the script twice');
first.expire();
check(!first.banner.hidden && first.window['ga-disable-G-TEST123'] === true, 'Expiry disables measurement and asks again in an already-open page');

const denied = harness({raw:'denied'});
check(denied.scripts.length === 0 && denied.banner.hidden && JSON.parse(denied.store.get(STORAGE)).source === 'legacy-denied', 'Legacy rejection is safely preserved without manufacturing acceptance');
const owner = harness({raw:saved('granted'), internal:true});
check(owner.scripts.length === 0 && owner.store.get(INTERNAL) === '1', 'Owner account exclusion persists before any GA script');
const loggedOutOwner = harness({store:owner.store});
check(loggedOutOwner.scripts.length === 0, 'Owner exclusion survives logout');
const local = harness({raw:saved('granted'), href:'http://localhost/releases'});
check(local.scripts.length === 0 && local.banner.hidden, 'Local environment remains excluded');
const failedRevocationStorage = harness({raw:saved('granted')});
failedRevocationStorage.blockWrites();
failedRevocationStorage.settings.click();
const before = failedRevocationStorage.commands().length;
failedRevocationStorage.clickDownload('/descargar/en/sdk/3.0.4/instalador');
failedRevocationStorage.window.gtag('event','late_callback');
check(failedRevocationStorage.window['ga-disable-G-TEST123'] === true && failedRevocationStorage.commands().length === before, 'Revocation remains effective in this page even if storing the refusal fails');
const failedAcceptStorage = harness();
failedAcceptStorage.blockWrites(); failedAcceptStorage.accept.click();
check(failedAcceptStorage.scripts.length === 0, 'Unavailable consent storage fails closed');
console.log(JSON.stringify({passed:true, flows:1, assertions, scope:'Fake DOM/storage/cookies; no browser, Google script execution, network or production access'}));
