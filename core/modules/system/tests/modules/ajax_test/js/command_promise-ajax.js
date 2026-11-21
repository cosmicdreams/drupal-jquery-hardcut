/**
 * @file
 * Tests the execution order of commands that return promises.
 */

((Drupal) => {
  /**
   * Test Ajax execution Order.
   *
   * @param {Drupal.Ajax} [ajax]
   *   {@link Drupal.Ajax} object created by {@link Drupal.Ajax}.
   * @param {object} response
   *   The response from the Ajax request.
   * @param {string} response.selector
   *   The selector for the insertion target.
   *
   * @return {Promise}
   *  The promise that will resolve once this command has finished executing.
   */
  Drupal.AjaxCommands.prototype.ajaxCommandReturnPromise = function (
    ajax,
    response,
  ) {
    return new Promise((resolve) => {
      setTimeout(resolve, 0);
    }).then(() => this.insert(ajax, response));
  };
})(Drupal);
