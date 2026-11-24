/**
 * @file
 * Defines JavaScript behaviors for the media type form.
 */

(function (Drupal) {
  /**
   * Behaviors for setting summaries on media type form.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attaches summary behaviors on media type edit forms.
   */
  Drupal.behaviors.mediaTypeFormSummaries = {
    attach(context) {
      // Provide the vertical tab summaries.
      Drupal.form.setSummary(
        context.querySelectorAll('#edit-workflow'),
        (context) => {
          const values = [];
          context
            .querySelectorAll('input[name^="options"]:checked')
            .forEach((input) =>
              values.push(
                Drupal.checkPlain(
                  input.parentElement.querySelector('label').textContent,
                ),
              ),
            );
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
    },
  };
})(Drupal);
