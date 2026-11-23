/**
 * @file
 * Lets media previews toggle their selection checkboxes.
 */

((Drupal, once) => {
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
          // Select the item instead of following a link in its preview.
          event.preventDefault();
          const checkbox = trigger
            .closest('.js-click-to-select')
            .querySelector('.js-click-to-select-checkbox input');
          if (checkbox) {
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
        const item = checkbox.closest('.js-click-to-select');
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
        '.js-click-to-select-trigger, .js-click-to-select-checkbox',
        context,
      ).forEach((region) => {
        const item = region.closest('.js-click-to-select');
        region.addEventListener('mouseenter', () =>
          item.classList.add('is-hover'),
        );
        region.addEventListener('mouseleave', () =>
          item.classList.remove('is-hover'),
        );
      });
    },
  };
})(Drupal, once);
