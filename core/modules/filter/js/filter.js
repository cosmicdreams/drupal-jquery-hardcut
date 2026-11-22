/**
 * @file
 * Displays guidelines for the selected text format.
 */

((Drupal) => {
  Drupal.behaviors.filterGuidelines = {
    attach(context) {
      const wrappers = new Set();
      once('filter-guidelines', '.js-filter-guidelines', context).forEach(
        (guidelines) => {
          guidelines
            .querySelectorAll('h1, h2, h3, h4, h5, h6')
            .forEach((heading) => {
              heading.style.display = 'none';
              wrappers.add(heading.closest('.js-filter-wrapper'));
            });
        },
      );
      wrappers.forEach((wrapper) => {
        wrapper?.querySelectorAll('select.js-filter-list').forEach((select) => {
          const update = () => {
            wrapper
              .querySelectorAll('[data-drupal-format-id]')
              .forEach((guideline) => {
                guideline.style.display =
                  guideline.dataset.drupalFormatId === select.value
                    ? ''
                    : 'none';
              });
          };
          Drupal.formEvents.listen(
            select,
            'change',
            'filterGuidelines',
            update,
          );
          update();
        });
      });
    },
  };
})(Drupal);
