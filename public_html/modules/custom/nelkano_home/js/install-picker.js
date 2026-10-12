(function () {
  'use strict';
  function detectPlatform(userAgent, platform) {
    var hint = (userAgent || '') + ' ' + (platform || '');
    if (/Android/i.test(hint)) return 'android';
    if (/Windows|Win32|Win64/i.test(hint)) return 'windows';
    if (/SteamOS|Steam Deck|SteamDeck|Linux/i.test(hint)) return 'steamos';
    return null;
  }
  function initialize(root, userAgent, platform) {
    var choices = Array.from(root.querySelectorAll('[data-install-platform]'));
    var link = root.querySelector('[data-install-link]');
    if (!link || choices.length === 0) return;
    function choose(button) {
      link.setAttribute('href', button.getAttribute('data-url'));
      root.querySelector('[data-install-label]').textContent = button.getAttribute('data-label');
      root.querySelector('[data-install-symbol]').replaceChildren(button.querySelector('svg').cloneNode(true));
      choices.forEach(function (candidate) {
        candidate.setAttribute('aria-pressed', candidate === button ? 'true' : 'false');
        candidate.hidden = candidate === button;
      });
      var alternatives = root.querySelector('[data-install-alternatives]');
      if (alternatives) alternatives.hidden = choices.length <= 1;
    }
    choices.forEach(function (button) { button.addEventListener('click', function () { choose(button); }); });
    var detected = detectPlatform(userAgent, platform);
    choose(choices.find(function (button) { return button.getAttribute('data-install-platform') === detected; }) || choices[0]);
  }
  if (typeof module !== 'undefined' && module.exports) module.exports = { detectPlatform: detectPlatform, initialize: initialize };
  if (typeof document !== 'undefined') document.querySelectorAll('[data-install-picker]').forEach(function (root) { initialize(root, navigator.userAgent, navigator.userAgentData ? navigator.userAgentData.platform : navigator.platform); });
})();
