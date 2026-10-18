(function () {
  'use strict';

  function prepare(header) {
    if (header.dataset.nkHeaderReady === 'true') {
      return;
    }
    var toggle = header.querySelector('[data-nk-navigation-toggle]');
    var navigation = header.querySelector('.nk-main-menu');
    var account = header.querySelector('[data-nk-account]');
    var accountTrigger = account && account.querySelector('summary');
    var mobile = window.matchMedia('(max-width: 900px)');
    if (!toggle || !navigation) {
      return;
    }
    header.dataset.nkHeaderReady = 'true';
    toggle.hidden = false;

    function closeAccount(restoreFocus) {
      if (account && account.open) {
        account.open = false;
        if (restoreFocus) {
          accountTrigger.focus();
        }
      }
    }

    function setNavigation(open, restoreFocus) {
      header.classList.toggle('nk-navigation-open', open);
      toggle.setAttribute('aria-expanded', String(open));
      toggle.setAttribute('aria-label', open ? toggle.dataset.closeLabel : toggle.dataset.openLabel);
      if (restoreFocus) {
        toggle.focus();
      }
    }

    toggle.addEventListener('click', function () {
      closeAccount(false);
      setNavigation(toggle.getAttribute('aria-expanded') !== 'true', false);
    });

    header.addEventListener('keydown', function (event) {
      if (event.key !== 'Escape') {
        return;
      }
      if (account && account.open) {
        event.preventDefault();
        closeAccount(true);
      }
      else if (mobile.matches && toggle.getAttribute('aria-expanded') === 'true') {
        event.preventDefault();
        setNavigation(false, true);
      }
    });

    document.addEventListener('click', function (event) {
      if (account && !account.contains(event.target)) {
        closeAccount(account.contains(document.activeElement));
      }
      if (mobile.matches && !navigation.contains(event.target) && !toggle.contains(event.target)) {
        setNavigation(false, navigation.contains(document.activeElement));
      }
    });

    header.addEventListener('focusout', function (event) {
      var next = event.relatedTarget;
      if (!next) {
        return;
      }
      if (account && !account.contains(next)) {
        closeAccount(false);
      }
      if (mobile.matches && !navigation.contains(next) && !toggle.contains(next)) {
        setNavigation(false, false);
      }
    });

    navigation.addEventListener('click', function (event) {
      if (mobile.matches && event.target.closest('a')) {
        setNavigation(false, false);
      }
    });
    mobile.addEventListener('change', function () {
      var navigationHadFocus = navigation.contains(document.activeElement);
      setNavigation(false, mobile.matches && navigationHadFocus);
      closeAccount(account && account.contains(document.activeElement));
    });
  }

  function attach(context) {
    context.querySelectorAll('[data-nk-header]').forEach(prepare);
  }
  if (window.Drupal && window.Drupal.behaviors) {
    window.Drupal.behaviors.nelkanoHeader = { attach: attach };
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { attach(document); });
  }
  else {
    attach(document);
  }
}());
