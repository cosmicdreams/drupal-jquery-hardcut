/**
 * @file
 * Attaches behaviors for the form_test module.
 */
(function (Drupal) {
  /**
   * Behavior for setting dynamic summaries.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attaches summary behavior on path edit forms.
   */
  Drupal.behaviors.formTestVerticalTabsSummary = {
    attach(context) {
      Drupal.form.setSummary(
        context.querySelectorAll('[data-drupal-selector="edit-tab1"]'),
        'Summary 1',
      );
      Drupal.form.setSummary(
        context.querySelectorAll('[data-drupal-selector="edit-tab2"]'),
        'Summary 2',
      );
    },
  };
})(Drupal);
