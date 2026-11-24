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
    // eslint-disable-next-line drupal-jquery/no-reference -- Simulates an existing global for the canary test.
    window.jQuery = 'existing jQuery';
  }
})();
