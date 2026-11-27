/**
 * @file
 *  Testing behavior for JSWebAssertTest.
 */

((Drupal) => {
  /**
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Makes changes in the DOM to be able to test the completion of AJAX in assertWaitOnAjaxRequest.
   */
  Drupal.behaviors.jswebassert_test_wait_for_ajax_request = {
    attach(context) {
      const delays = {
        'edit-test-assert-no-element-after-wait-pass': 500,
        'edit-test-assert-no-element-after-wait-fail': 2000,
      };
      Object.entries(delays).forEach(([id, delay]) => {
        once('jswebassert-remove', `#${id}`, context).forEach((button) => {
          button.addEventListener('click', (event) => {
            event.preventDefault();
            setTimeout(() => button.remove(), delay);
          });
        });
      });
    },
  };
})(Drupal);
