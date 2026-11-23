/**
 * @file
 * Filters the enabled and disabled Views listings by name and description.
 */

((Drupal, once) => {
  Drupal.behaviors.viewTableFilterByText = {
    attach(context) {
      once('views-filter-text', 'input.views-filter-text', context).forEach((input) => {
        const filter = () => {
          const query = input.value.toLowerCase();
          document.querySelector(input.dataset.table)?.querySelectorAll('tbody tr').forEach((row) => {
            const text = Array.from(
              row.querySelectorAll('[data-drupal-selector="views-table-filter-text-source"]'),
              (source) => source.textContent,
            ).join('').toLowerCase();
            row.hidden = query.length >= 2 && !text.includes(query);
          });
        };
        input.addEventListener('input', filter);
        filter();
      });
    },
  };
})(Drupal, once);
