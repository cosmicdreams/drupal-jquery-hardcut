/**
 * @file
 * Marks the results once the Ajax-loaded widget has initialized.
 */

Drupal.behaviors.viewsRefreshWidget = {
  attach(context) {
    context.querySelectorAll('.view-content tbody').forEach((results) => {
      results.dataset.refreshWidget = '';
    });
  },
};
