const assert = require('node:assert/strict');
const { detectPlatform, initialize } = require('../public_html/modules/custom/nelkano_home/js/install-picker.js');
for (const [ua, platform, expected] of [
  ['Mozilla Android 15 Linux','Linux','android'], ['Mozilla Windows NT 10.0','Win32','windows'],
  ['Mozilla X11 Linux x86_64','Linux','steamos'], ['SteamOS Steam Deck','','steamos'],
  ['Mozilla iPhone OS 18','','null'], ['','macOS','null'], ['', 'Windows', 'windows']
]) assert.equal(detectPlatform(ua, platform), expected === 'null' ? null : expected);
function fixture(platforms) {
  const link = { attrs:{},setAttribute(k,v){this.attrs[k]=v;} };
  const alternatives = {hidden:false};
  const label = {textContent:''}; const symbol = {replaceChildren(value){this.value=value;}};
  const choices = platforms.map(platform => ({attrs:{'data-install-platform':platform,'data-url':'/download/'+platform,'data-label':'Instalar en '+platform},listeners:{},getAttribute(k){return this.attrs[k];},setAttribute(k,v){this.attrs[k]=v;},addEventListener(k,handler){this.listeners[k]=handler;},querySelector(){return {cloneNode(){return platform;}};}}));
  return {link,label,symbol,choices,alternatives,querySelectorAll(){return choices;},querySelector(selector){return {'[data-install-alternatives]':alternatives,'[data-install-link]':link,'[data-install-label]':label,'[data-install-symbol]':symbol}[selector];}};
}
const all = fixture(['android','windows','steamos']); initialize(all,'Windows NT 10.0','Win32');
assert.equal(all.link.attrs.href, '/download/windows'); assert.equal(all.label.textContent,'Instalar en windows'); assert.equal(all.symbol.value,'windows');
all.choices[2].listeners.click(); assert.equal(all.link.attrs.href,'/download/steamos'); assert.equal(all.choices[2].attrs['aria-pressed'],'true'); assert.equal(all.choices[1].attrs['aria-pressed'],'false');
const hidden = fixture(['android']); initialize(hidden,'Windows NT 10.0','Win32'); assert.equal(hidden.link.attrs.href,'/download/android');
console.log('INSTALL_PICKER_QA_PASS: OS detection, manual selection, icon/label/link synchronization and hidden-platform fallback');

assert.equal(all.alternatives.hidden, false);
assert.deepEqual(all.choices.filter(choice => !choice.hidden).map(choice => choice.attrs['data-install-platform']), ['android', 'windows']);
all.choices[0].listeners.click();
assert.deepEqual(all.choices.filter(choice => !choice.hidden).map(choice => choice.attrs['data-install-platform']), ['windows', 'steamos']);
assert.equal(hidden.alternatives.hidden, true);
assert.equal(hidden.choices[0].hidden, true);
const pair = fixture(['android', 'windows']); initialize(pair, 'Windows NT 10.0', 'Win32');
assert.deepEqual(pair.choices.filter(choice => !choice.hidden).map(choice => choice.attrs['data-install-platform']), ['android']);
pair.choices[0].listeners.click();
assert.deepEqual(pair.choices.filter(choice => !choice.hidden).map(choice => choice.attrs['data-install-platform']), ['windows']);
console.log('INSTALL_ALTERNATIVES_QA_PASS: single-platform row hidden and selected platform excluded after detection and manual switching');