/*
Future color-mode toggle.

(function () {
  var storageKey = 'nelkano-theme';
  var root = document.documentElement;
  var buttons = document.querySelectorAll('[data-theme-toggle]');

  function preferredTheme() {
    try {
      var saved = window.localStorage.getItem(storageKey);
      if (saved === 'light' || saved === 'dark') {
        return saved;
      }
    }
    catch (error) {}

    return window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
  }

  function label(theme, language) {
    if (language === 'en') {
      return theme === 'light' ? 'Switch to dark mode' : 'Switch to light mode';
    }
    return theme === 'light' ? 'Cambiar a modo oscuro' : 'Cambiar a modo claro';
  }

  function applyTheme(theme) {
    var nextTheme = theme === 'light' ? 'light' : 'dark';
    root.setAttribute('data-theme', nextTheme);
    root.style.colorScheme = nextTheme;
    buttons.forEach(function (button) {
      var language = button.getAttribute('data-theme-language') || 'es';
      button.setAttribute('aria-label', label(nextTheme, language));
      button.setAttribute('title', label(nextTheme, language));
    });
  }

  applyTheme(root.getAttribute('data-theme') || preferredTheme());

  buttons.forEach(function (button) {
    button.addEventListener('click', function () {
      var nextTheme = root.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
      applyTheme(nextTheme);
      try {
        window.localStorage.setItem(storageKey, nextTheme);
      }
      catch (error) {}
    });
  });
}());
*/
