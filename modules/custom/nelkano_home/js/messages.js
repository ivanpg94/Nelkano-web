(function (Drupal) {
  'use strict';

  var MESSAGE_SELECTOR = '[data-drupal-messages] .messages, .messages';
  var DISMISS_DELAY = 5200;
  var REMOVE_DELAY = 260;

  function messageContainers(message) {
    var containers = [];
    var current = message.parentElement;
    while (current && current !== document.body) {
      if (
        current.matches('[data-drupal-messages], .messages__wrapper') ||
        current.getAttribute('data-drupal-selector') === 'messages'
      ) {
        containers.push(current);
      }
      current = current.parentElement;
    }

    return containers;
  }

  function cleanupContainers(containers) {
    containers.forEach(function (container) {
      if (!container.querySelector('.messages')) {
        container.style.margin = '0';
        container.style.gap = '0';
      }
    });
  }

  function dismiss(message) {
    if (!message || message.dataset.nkMessageLeaving === 'true') {
      return;
    }

    message.dataset.nkMessageLeaving = 'true';
    var containers = messageContainers(message);
    message.style.height = message.scrollHeight + 'px';
    message.style.overflow = 'hidden';

    window.requestAnimationFrame(function () {
      message.classList.add('nk-message-leaving');
      message.style.height = '0px';
    });

    window.setTimeout(function () {
      message.remove();
      cleanupContainers(containers);
    }, REMOVE_DELAY);
  }

  function prepare(context) {
    var root = context || document;
    var messages = root.querySelectorAll(MESSAGE_SELECTOR);

    messages.forEach(function (message) {
      if (message.dataset.nkMessageReady === 'true') {
        return;
      }

      message.dataset.nkMessageReady = 'true';
      window.setTimeout(function () {
        dismiss(message);
      }, DISMISS_DELAY);
    });
  }

  if (Drupal && Drupal.behaviors) {
    Drupal.behaviors.nelkanoMessages = {
      attach: prepare
    };
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      prepare(document);
    });
  }
  else {
    prepare(document);
  }
}(window.Drupal));
