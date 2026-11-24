/**
 * @file
 * Reads both legacy globals from the same script.
 */

// eslint-disable-next-line drupal-jquery/no-reference -- Exercises the diagnostic.
document.body.dataset.jquery = typeof window.jQuery;
// eslint-disable-next-line drupal-jquery/no-reference -- Exercises the diagnostic.
document.body.dataset.alias = typeof window.$;
