/**
 * @file
 * Keeps the Views dialog header and actions in view while its options scroll.
 */

/**
 * Registers resize handling for Views dialogs.
 * @param {Drupal} Drupal
 *   The Drupal object.
 */
((Drupal) => {
  window.addEventListener(
    'dialog:beforecreate',
    ({ target, dialog, settings }) => {
      if (
        !dialog.element.matches('.views-ui-dialog') &&
        !settings.classes?.['drupal-dialog']
          ?.split(' ')
          .includes('views-ui-dialog')
      ) {
        return;
      }
      const scroll = target.querySelector('[data-drupal-views-scroll]');
      if (scroll) {
        settings.scrollTarget = scroll;
        dialog.element.classList.add('views-ui-dialog-scroll');
      }
    },
  );

  document.addEventListener('dialogContentResize', ({ target }) => {
    const content = target.closest('.views-ui-dialog .drupal-dialog-content');
    const scroll = content?.querySelector('[data-drupal-views-scroll]');
    if (!scroll || !Drupal.elementIsVisible(content)) {
      return;
    }

    // Measure the options at their natural height and reserve space for
    // everything outside the scroll area, including messages and actions.
    const scrollTop = scroll.scrollTop;
    scroll.style.maxHeight = '';
    const outside = content.scrollHeight - scroll.offsetHeight;
    const styles = getComputedStyle(scroll);
    const decoration =
      styles.boxSizing === 'border-box'
        ? 0
        : [
            'paddingTop',
            'paddingBottom',
            'borderTopWidth',
            'borderBottomWidth',
          ].reduce((sum, side) => sum + Number.parseFloat(styles[side]), 0);
    scroll.style.maxHeight = `${Math.max(0, content.clientHeight - outside - decoration)}px`;
    scroll.scrollTop = scrollTop;
  });

  document.addEventListener(
    'toggle',
    ({ target }) => {
      if (
        target.matches('details') &&
        target.closest('[data-drupal-views-scroll]')
      ) {
        target.dispatchEvent(
          new Event('dialogContentResize', { bubbles: true }),
        );
      }
    },
    true,
  );
})(Drupal);
