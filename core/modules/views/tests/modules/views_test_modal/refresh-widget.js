/**
 * @file
 * Marks the results once the Ajax-loaded widget has initialized.
 */

Drupal.behaviors.viewsRefreshWidget = {
  attach(context) {
    context
      .querySelectorAll('[class*="js-view-dom-id-"] tbody')
      .forEach((results) => {
        results.dataset.refreshWidget = '';
      });
  },
};
