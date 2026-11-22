/**
 * @file
 * Captures Drupal deprecation notices before States behaviors attach.
 * @param {object} Drupal
 *   The global Drupal namespace.
 */

((Drupal) => {
  window.statesDeprecationLog = [];
  const original = Drupal.deprecationError;
  Drupal.deprecationError = (deprecation) => {
    window.statesDeprecationLog.push(deprecation?.message ?? '');
    return original(deprecation);
  };
})(Drupal);
