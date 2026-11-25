/**
 * @file
 * JavaScript for the node content editing form.
 */

(function (Drupal) {
  /**
   * Behaviors for setting summaries on content type form.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attaches summary behaviors on content type edit forms.
   */
  Drupal.behaviors.contentTypes = {
    attach(context) {
      // Provide the vertical tab summaries.
      Drupal.form.setSummary(
        context.querySelectorAll('#edit-submission'),
        (context) => {
          const values = [];
          values.push(
            Drupal.checkPlain(
              context.querySelector('#edit-title-label').value,
            ) || Drupal.t('Requires a title'),
          );
          return values.join(', ');
        },
      );
      Drupal.form.setSummary(
        context.querySelectorAll('#edit-workflow'),
        (context) => {
          const values = [];
          context
            .querySelectorAll('input[name^="options"]:checked + label')
            .forEach((label) => {
              values.push(Drupal.checkPlain(label.textContent));
            });
          if (!context.querySelector('#edit-options-status:checked')) {
            values.unshift(Drupal.t('Not published'));
          }
          return values.join(', ');
        },
      );
      Drupal.form.setSummary(
        context.querySelectorAll('#edit-language'),
        (context) => {
          const values = [];

          values.push(
            context.querySelector(
              '.js-form-item-language-configuration-langcode select option:checked',
            ).textContent,
          );

          context.querySelectorAll('input:checked + label').forEach((label) => {
            values.push(Drupal.checkPlain(label.textContent));
          });

          return values.join(', ');
        },
      );
      Drupal.form.setSummary(
        context.querySelectorAll('#edit-display'),
        (context) => {
          const values = [];
          context.querySelectorAll('input:checked + label').forEach((label) => {
            values.push(Drupal.checkPlain(label.textContent));
          });
          if (!context.querySelector('#edit-display-submitted:checked')) {
            values.unshift(Drupal.t("Don't display post information"));
          }
          return values.join(', ');
        },
      );
    },
  };
})(Drupal);
