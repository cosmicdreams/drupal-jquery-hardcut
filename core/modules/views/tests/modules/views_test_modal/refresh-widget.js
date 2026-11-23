/**
 * @file
 * Marks the results once the Ajax-loaded widget has initialized.
 */

Drupal.behaviors.viewsRefreshWidget = {
  attach(context) {
    context.querySelectorAll('.views-table tbody').forEach((results) => {
      results.dataset.refreshWidget = '';
    });
  },
};
