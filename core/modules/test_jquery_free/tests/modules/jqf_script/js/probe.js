/**
 * @file
 * Reads both legacy globals from the same script.
 */

// eslint-disable-next-line drupal-jquery/no-reference -- Exercises both globals from the same location.
const [jquery, alias] = [typeof window.jQuery, typeof window.$];
document.body.dataset.jquery = jquery;
document.body.dataset.alias = alias;
