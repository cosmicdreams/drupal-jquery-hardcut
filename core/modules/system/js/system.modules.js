/**
 * @file
 * Filters the module listing by its text search field.
 */

((Drupal, debounce) => {
  Drupal.behaviors.tableFilterByText = {
    attach() {
      const [input] = once('table-filter-text', 'input.table-filter-text');
      if (!input) {
        return;
      }
      const containers = Array.from(
        document.querySelectorAll(input.dataset.table),
      );
      if (!containers.length) {
        return;
      }

      const rowsAndDetails = Array.from(
        new Set(
          containers.flatMap((container) =>
            Array.from(container.querySelectorAll('tr, details')),
          ),
        ),
      );
      const rows = rowsAndDetails.filter((element) =>
        element.matches('tbody tr'),
      );
      const packages = rowsAndDetails.filter((element) =>
        element.matches('.package-listing'),
      );
      let searching = false;

      const filterModuleList = ({ target }) => {
        const query = target.value;
        // Match a search term at the beginning of a word.
        const pattern = new RegExp(`\\b${query}`, 'i');
        rowsAndDetails.forEach((element) => {
          element.style.display = '';
        });

        if (query.length >= 2) {
          searching = true;
          rows.forEach((row) => {
            const sources = row.querySelectorAll(
              '.table-filter-text-source, .module-name, .module-description',
            );
            const text = Array.from(
              sources,
              (element) => ` ${element.textContent}`,
            ).join('');
            row.style.display = pattern.test(text) ? '' : 'none';
          });

          packages.forEach((details) => {
            if (!details.open) {
              details.dataset.drupalSystemState = 'forced-open';
            }
            details.setAttribute('open', 'true');
            if (
              !Array.from(details.querySelectorAll('tbody tr')).some(
                (row) => row.getClientRects().length,
              )
            ) {
              details.style.display = 'none';
            }
          });

          const visible = rows.filter(
            (row) => row.getClientRects().length,
          ).length;
          Drupal.announce(
            Drupal.formatPlural(
              visible,
              '1 module is available in the modified list.',
              '@count modules are available in the modified list.',
            ),
          );
        } else if (searching) {
          searching = false;
          packages.forEach((details) => {
            if (details.dataset.drupalSystemState === 'forced-open') {
              delete details.dataset.drupalSystemState;
              details.open = false;
            }
          });
        }
      };

      Drupal.formEvents.listen(
        input,
        'input',
        undefined,
        debounce(filterModuleList, 200),
      );
      Drupal.formEvents.listen(input, 'keydown', undefined, (event) => {
        if (
          event.key === 'Enter' ||
          event.which === 13 ||
          event.keyCode === 13
        ) {
          event.preventDefault();
          event.stopPropagation();
        }
      });
    },
  };
})(Drupal, Drupal.debounce);
