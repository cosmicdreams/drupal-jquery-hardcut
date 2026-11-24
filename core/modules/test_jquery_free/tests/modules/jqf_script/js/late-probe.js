/**
 * @file
 * Reads the canary after a script assigns undefined.
 */

if (!new URLSearchParams(location.search).has('preset')) {
  // eslint-disable-next-line drupal-jquery/no-reference -- Tests that the diagnostic stays armed.
  window.jQuery = undefined;
  // eslint-disable-next-line drupal-jquery/no-reference -- Exercises the diagnostic at a second location.
  document.body.dataset.lateJquery = typeof window.jQuery;
  // eslint-disable-next-line drupal-jquery/no-reference -- Exercises another line in the same script.
  document.body.dataset.lateAlias = typeof window.$;
}
