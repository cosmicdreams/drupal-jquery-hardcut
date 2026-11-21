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
    attach() {
      const inputs = once('block-filter-text', 'input.block-filter-text');
      const tables = document.querySelectorAll(inputs[0]?.dataset.element);
      const rows = Array.from(tables).flatMap((table) =>
        Array.from(table.querySelectorAll('div.block-filter-text-source')),
      );
      if (!tables.length) {
        return;
      }
      const filter = ({ target }) => {
        const query = target.value.toLowerCase();
        rows.forEach((label) => {
          const visible =
            query.length < 2 || label.textContent.toLowerCase().includes(query);
          label.parentElement.parentElement.style.display = visible
            ? ''
            : 'none';
        });
        if (query.length >= 2) {
          const visible = Array.from(tables)
            .flatMap((table) => Array.from(table.querySelectorAll('tr')))
            .filter((row) => row.getClientRects().length).length;
          Drupal.announce(
            Drupal.formatPlural(
              visible - 1,
              '1 block is available in the modified list.',
              '@count blocks are available in the modified list.',
            ),
          );
        }
      };
      inputs.forEach((input) =>
        Drupal.formEvents.listen(
          input,
          'keyup',
          undefined,
          debounce(filter, 200),
        ),
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
