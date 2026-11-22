/**
 * @file
 * Defines JavaScript behaviors for the node module.
 */

(function (Drupal) {
  /**
   * Behaviors for tabs in the node edit form.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attaches summary behavior for tabs in the node edit form.
   */
  Drupal.behaviors.nodeDetailsSummaries = {
    attach(context) {
      Drupal.form.setSummary(
        context.querySelectorAll('.node-form-author'),
        (context) => {
          const nameElement = context.querySelector('.field--name-uid input');
          const name = nameElement?.value;
          const dateElement = context.querySelector(
            '.field--name-created input',
          );
          const date = dateElement?.value;

          if (name && date) {
            return Drupal.t('By @name on @date', {
              '@name': name,
              '@date': date,
            });
          }
          if (name) {
            return Drupal.t('By @name', { '@name': name });
          }
          if (date) {
            return Drupal.t('Authored on @date', { '@date': date });
          }
        },
      );

      Drupal.form.setSummary(
        context.querySelectorAll('.node-form-options'),
        (context) => {
          if (context.querySelector('input:checked')) {
            return Array.from(
              context.querySelectorAll('input:checked + label'),
              (label) => Drupal.checkPlain(label.textContent.trim()),
            ).join(', ');
          }

          return Drupal.t('Not promoted');
        },
      );
    },
  };
})(Drupal);
