/**
 * @file
 *  Testing behavior for JSWebAssertTest.
 */

(function (Drupal) {
  /**
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Makes changes in the DOM to be able to test the completion of AJAX in assertWaitOnAjaxRequest.
   */
  Drupal.behaviors.jswebassert_test_wait_for_element = {
    attach() {
      document
        .getElementById('jswebassert_test_element_invisible')
        ?.style.removeProperty('display');
    },
  };
})(Drupal);
