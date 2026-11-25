/**
 * @file
 * Media Library overrides for Default Admin
 */
((Drupal, once) => {
  /**
   * Update the media library selection when loaded or media items are selected.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attaches behavior to select media items.
   */
  Drupal.behaviors.mediaLibraryItemSelectionDefaultAdmin = {
    attach() {
      // Move the selection count to the beginning of the button pane after it
      // has been added to the Media Library dialog.
      // @todo replace with theme function override in
      //   https://drupal.org/node/3134526
      if (!once('media-library-selection-info-admin-event', 'html').length) {
        return;
      }
      window.addEventListener('dialog:aftercreate', ({ target }) => {
        const pane = target
          .closest('.media-library-widget-modal')
          ?.querySelector('.drupal-dialog-buttonpane');
        if (!pane) {
          return;
        }
        const selector = '.js-media-library-selected-count';
        const count = pane.querySelector(selector);
        if (count) {
          pane.prepend(count);
        } else {
          // The counter can be added by a later dialog listener.
          const observer = new MutationObserver(() => {
            const counter = pane.querySelector(selector);
            if (counter) {
              observer.disconnect();
              pane.prepend(counter);
            }
          });
          observer.observe(pane, { childList: true, subtree: true });
          target.addEventListener(
            'dialog:beforeclose',
            () => observer.disconnect(),
            { once: true },
          );
        }
      });
    },
  };
})(Drupal, once);
