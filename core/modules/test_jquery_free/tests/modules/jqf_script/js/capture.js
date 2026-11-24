/**
 * @file
 * Captures browser warnings for assertions.
 */

(() => {
  const warnings = [];
  const warn = console.warn.bind(console);
  console.warn = (...args) => {
    warnings.push(args);
    warn(...args);
  };
  window.jqfWarnings = warnings;

  if (new URLSearchParams(location.search).has('preset')) {
    Reflect.set(window, 'jQuery', 'existing jQuery');
  }
})();
