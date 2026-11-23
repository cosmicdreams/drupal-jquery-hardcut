/**
 * @file
 * Filters the enabled and disabled Views listings by name and description.
 */

/**
 * Registers the filter for both listing tables.
 * @param {Drupal} Drupal
 *   The Drupal object.
 * @param {typeof window.once} once
 *   Marks elements already processed by a behavior.
 */
((Drupal, once) => {
  Drupal.behaviors.viewTableFilterByText = {
    attach(context) {
      once('views-filter-text', 'input.views-filter-text', context).forEach(
        (input) => {
          const filter = () => {
            const query = input.value.toLowerCase();
            const rows = document.querySelectorAll(
              `:is(${input.dataset.table}) tbody tr`,
            );
            rows.forEach((row) => {
              const sources = row.querySelectorAll(
                '[data-drupal-selector="views-table-filter-text-source"]',
              );
              let text = '';
              for (const source of sources) {
                text += source.textContent;
              }
              row.hidden =
                query.length >= 2 && !text.toLowerCase().includes(query);
            });
          };
          input.addEventListener('input', filter);
          filter();
        },
      );
    },
  };
})(Drupal, once);
