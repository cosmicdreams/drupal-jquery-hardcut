/**
 * @file
 * Some basic behaviors and utility functions for Views.
 */

/**
 * Registers helpers for interpreting Views links.
 * @param {Drupal} Drupal
 *   The Drupal object.
 * @param {object} drupalSettings
 *   Settings for the current Drupal page.
 */
(function (Drupal, drupalSettings) {
  /**
   * @namespace
   */
  Drupal.Views = {};

  /**
   * Helper function to parse a querystring.
   * @param {string} query
   *   The querystring to parse.
   * @return {object}
   *   A map of query parameters.
   */
  Drupal.Views.parseQueryString = function (query) {
    const args = new URLSearchParams(query);
    // Ignore the legacy path query parameter if present.
    args.delete('q');
    return Object.fromEntries(args);
  };

  /**
   * Helper function to return a view's arguments based on a path.
   * @param {string} href
   *   The href to check.
   * @param {string} viewPath
   *   The views path to check.
   * @return {object}
   *   An object containing `view_args` and `view_path`.
   */
  Drupal.Views.parseViewArgs = function (href, viewPath) {
    const returnObj = {};
    const path = Drupal.Views.getPath(href);
    // Get viewPath URL without baseUrl portion.
    const viewHref = Drupal.url(viewPath).substring(
      drupalSettings.path.baseUrl.length,
    );
    // Ensure we have a correct path.
    if (viewHref && path.startsWith(`${viewHref}/`)) {
      returnObj.view_args = decodeURIComponent(
        path.substring(viewHref.length + 1, path.length),
      );
      returnObj.view_path = path;
    }
    return returnObj;
  };

  /**
   * Return the Drupal path portion of an href.
   * @param {string} href
   *   The href to check.
   * @return {string}
   *   An internal path.
   */
  Drupal.Views.getPath = function (href) {
    const url = new URL(href, document.baseURI);
    if (
      url.origin !== window.location.origin ||
      !url.pathname.startsWith(drupalSettings.path.baseUrl)
    ) {
      return '';
    }
    return url.pathname.slice(drupalSettings.path.baseUrl.length);
  };
})(Drupal, drupalSettings);
