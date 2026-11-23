/**
 * @file
 * Lets media previews toggle their selection checkboxes.
 */

((Drupal, once) => {
  const itemSelector = '.js-click-to-select';
  const checkboxSelector = '.js-click-to-select-checkbox';
  const hoverSelector = `.js-click-to-select-trigger, ${checkboxSelector}`;

  /**
   * Allows users to select an element which checks a hidden checkbox.
   *
   * @type {Drupal~behavior}
   */
  Drupal.behaviors.ClickToSelect = {
    attach(context) {
      once(
        'media-library-click-to-select',
        '.js-click-to-select-trigger',
        context,
      ).forEach((trigger) => {
        trigger.addEventListener('click', (event) => {
          const checkbox = trigger
            .closest(itemSelector)
            ?.querySelector(`${checkboxSelector} input`);
          if (
            checkbox &&
            event.target.closest(hoverSelector) !==
              checkbox.closest(checkboxSelector)
          ) {
            // Select the item instead of following a link in its preview.
            event.preventDefault();
            checkbox.checked = !checkbox.checked;
            checkbox.dispatchEvent(new Event('change', { bubbles: true }));
          }
        });
      });

      once(
        'media-library-click-to-select',
        '.js-click-to-select-checkbox input',
        context,
      ).forEach((checkbox) => {
        const item = checkbox.closest(itemSelector);
        if (!item) {
          return;
        }
        checkbox.addEventListener('change', () => {
          item.classList.toggle('checked', checkbox.checked);
        });
        checkbox.addEventListener('focus', () =>
          item.classList.add('is-focus'),
        );
        checkbox.addEventListener('blur', () =>
          item.classList.remove('is-focus'),
        );
      });

      once(
        'media-library-click-to-select-hover',
        hoverSelector,
        context,
      ).forEach((region) => {
        const item = region.closest(itemSelector);
        if (!item) {
          return;
        }
        region.addEventListener('mouseenter', () =>
          item.classList.add('is-hover'),
        );
        region.addEventListener('mouseleave', ({ relatedTarget }) => {
          // The pointer may still be over another selection region of this item.
          if (
            !(relatedTarget instanceof Element) ||
            relatedTarget.closest(hoverSelector)?.closest(itemSelector) !== item
          ) {
            item.classList.remove('is-hover');
          }
        });
      });
    },
  };
})(Drupal, once);
