/**
 * @file
 * Testing behaviors for tabledrag library.
 */
((Drupal, once) => {
  /**
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Removes a test class from the handle elements to allow verifying that
   *   dragging operations have been executed.
   */
  Drupal.behaviors.tableDragTest = {
    attach(context) {
      once('tabledrag-test', '.tabledrag-handle', context).forEach((handle) => {
        handle.addEventListener('keydown', () =>
          handle.classList.remove('tabledrag-test-dragging'),
        );
      });
    },
  };
})(Drupal, once);
