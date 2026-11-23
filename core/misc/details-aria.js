/**
 * @file
 * Add aria attribute handling for details and summary elements.
 */

(function (Drupal) {
  /**
   * Handles `aria-expanded` on details summaries.
   *
   * @type {Drupal~behavior}
   */
  Drupal.behaviors.detailsAria = {
    attach() {
      once('detailsAria', 'body').forEach((target) => {
        target.addEventListener('click', (event) => {
          if (event.defaultPrevented || event.button >= 1) {
            return;
          }
          const summary = event.target.closest?.('summary');
          if (summary) {
            summary.setAttribute(
              'aria-expanded',
              String(!summary.parentElement.open),
            );
          }
        });
      });
    },
  };
})(Drupal);
