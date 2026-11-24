/**
 * @file
 * Block admin behaviors.
 */

(function (Drupal, debounce, once) {
  /**
   * Filters the block list by a text input search string.
   *
   * The text input will have the selector `input.block-filter-text`.
   *
   * The target element to do searching in will be in the selector
   * `input.block-filter-text[data-element]`
   *
   * The text source where the text should be found will have the selector
   * `.block-filter-text-source`
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attaches the behavior for the block filtering.
   */
  Drupal.behaviors.blockFilterByText = {
    attach(context) {
      once('block-filter-text', 'input.block-filter-text', context).forEach(
        (input) => {
          const table = (
            input.closest('dialog, main') || context
          ).querySelector(input.dataset.element);
          if (!table) {
            return;
          }
          const labels = table.querySelectorAll('div.block-filter-text-source');
          input.addEventListener(
            'keyup',
            debounce(() => {
              const query = input.value.toLowerCase();
              labels.forEach((label) => {
                const textMatch = label.textContent
                  .toLowerCase()
                  .includes(query);
                label.closest('tr').style.display =
                  query.length < 2 || textMatch ? '' : 'none';
              });
              if (query.length >= 2) {
                const visible = [...table.rows].filter(
                  (row) => row.getClientRects().length,
                ).length;
                Drupal.announce(
                  Drupal.formatPlural(
                    visible - 1,
                    '1 block is available in the modified list.',
                    '@count blocks are available in the modified list.',
                  ),
                );
              }
            }, 200),
          );
        },
      );
    },
  };

  /**
   * Highlights the block that was just placed into the block listing.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attaches the behavior for the block placement highlighting.
   */
  Drupal.behaviors.blockHighlightPlacement = {
    attach(context, settings) {
      // Ensure that the block we are attempting to scroll to actually exists.
      const placed = document.querySelector('.js-block-placed');
      if (settings.blockPlacement && placed) {
        once(
          'block-highlight',
          '[data-drupal-selector="edit-blocks"]',
          context,
        ).forEach((container) => {
          window.scrollTo({
            top:
              placed.getBoundingClientRect().top -
              container.getBoundingClientRect().top +
              container.scrollTop,
            behavior: 'smooth',
          });
        });
      }
    },
  };
})(Drupal, Drupal.debounce, once);
