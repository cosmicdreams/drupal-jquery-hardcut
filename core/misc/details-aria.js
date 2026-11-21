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
        const binding = {
          target,
          type: 'click',
          namespace: 'detailsAria',
          selector: 'summary',
          detailsAria: true,
          listener(summary) {
            summary.setAttribute(
              'aria-expanded',
              String(summary.parentNode.getAttribute('open') !== 'open'),
            );
          },
          stop() {
            target.removeEventListener('click', onClick);
            unregister();
          },
        };
        function onClick(event) {
          if (binding.handlesNativeEvent?.(event) || event.button >= 1) {
            return;
          }
          const summary = event.target.closest?.(binding.selector);
          if (summary) {
            binding.listener(summary);
          }
        }
        target.addEventListener('click', onClick);
        const unregister = Drupal.eventBindings.register(binding);
      });
    },
  };
})(Drupal);
